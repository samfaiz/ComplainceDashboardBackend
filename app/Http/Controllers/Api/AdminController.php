<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountRequest;
use App\Models\AuditLog;
use App\Models\Dashboard;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Security\AuditLogger;
use App\Services\Security\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private MfaService $mfa,
        private NotificationService $notifications,
    ) {}

    public function users(Request $request): JsonResponse
    {
        $actor = $request->user();

        $users = User::query()
            ->withCount('apiSources')
            // Admins only see accounts they can manage; super admins see everyone.
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->whereIn('role', [User::ROLE_ANALYST, User::ROLE_VIEWER]))
            ->latest()
            ->get()
            ->map(fn (User $u) => $this->userRow($u) + ['manageable' => $actor->outranks($u)]);

        return response()->json(['users' => $users]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);

        return response()->json([
            'user' => $this->userRow($user),
            'known_ips' => $user->knownIps()->orderByDesc('last_seen_at')->get(),
            'recent_logins' => $user->loginEvents()->latest('created_at')->limit(20)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => ['nullable', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $this->assertAssignableRole($request->user(), $data['role']);

        $generated = empty($data['password']);
        $password = $generated ? $this->randomPassword() : $data['password'];

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => Hash::make($password),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $this->audit->log('admin.user_created', $request->user(), $user, ['role' => $user->role]);

        // Populate a sample dashboard for the new account by default.
        if (config('security.demo_seed_new_users', true)) {
            try {
                app(\App\Services\Demo\DemoDataGenerator::class)->generateFor($user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'user' => $this->userRow($user),
            'temporary_password' => $generated ? $password : null,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->authorizeTarget($request, $user);

        if (array_key_exists('role', $data)) {
            $this->assertAssignableRole($request->user(), $data['role']);
        }

        $oldRole = $user->role;
        $user->update($data);

        // Re-enabling an account clears the brute-force lock/counter so the user
        // isn't immediately re-disabled on their next attempt.
        if (($data['is_active'] ?? null) === true) {
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
        }

        $this->audit->log('admin.user_updated', $request->user(), $user, $data);

        if (array_key_exists('role', $data) && $oldRole !== $data['role']) {
            $this->notifications->dispatch('account.role_changed', [
                'user' => ['email' => $user->email, 'name' => $user->name],
                'old_role' => $oldRole,
                'new_role' => $data['role'],
                'actor' => ['email' => $request->user()->email],
            ]);
        }

        return response()->json(['user' => $this->userRow($user->fresh())]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);

        $data = $request->validate([
            'password' => ['nullable', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $generated = empty($data['password']);
        $password = $generated ? $this->randomPassword() : $data['password'];

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->audit->log('admin.password_reset', $request->user(), $user);
        $this->resolveRequests($user, 'password', $request->user());

        // Notify the affected user (single recipient = the user themselves).
        $this->notifications->dispatch('account.password_reset', [
            'user' => ['email' => $user->email, 'name' => $user->name],
            'actor' => ['email' => $request->user()->email],
        ], $user);

        return response()->json([
            'message' => 'Password reset. The user must change it on next login.',
            'temporary_password' => $generated ? $password : null,
        ]);
    }

    public function clearIpFlag(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $user->forceFill(['ip_flagged' => false])->save();

        if ($user->current_ip) {
            $user->knownIps()->updateOrCreate(
                ['ip_address' => $user->current_ip],
                ['trusted' => true, 'last_seen_at' => now()]
            );
        }

        $this->audit->log('admin.ip_flag_cleared', $request->user(), $user, ['ip' => $user->current_ip]);

        return response()->json(['user' => $this->userRow($user->fresh())]);
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0])->save();
        $this->audit->log('admin.user_unlocked', $request->user(), $user);

        return response()->json(['user' => $this->userRow($user->fresh())]);
    }

    public function disableMfa(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $this->mfa->disable($user);
        $this->audit->log('admin.mfa_disabled', $request->user(), $user);
        $this->resolveRequests($user, 'mfa', $request->user());

        $this->notifications->dispatch('account.mfa_disabled', [
            'user' => ['email' => $user->email, 'name' => $user->name],
            'actor' => ['email' => $request->user()->email],
            'when' => now()->toDateTimeString(),
        ]);

        return response()->json(['user' => $this->userRow($user->fresh())]);
    }

    /** Toggle whether a user is allowed (and required on next login) to enroll MFA. */
    public function setMfaRequired(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $data = $request->validate(['required' => ['required', 'boolean']]);

        $user->forceFill(['mfa_required' => $data['required']])->save();
        $this->audit->log('admin.mfa_required_set', $request->user(), $user, ['required' => $data['required']]);

        return response()->json(['user' => $this->userRow($user->fresh())]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            abort(422, 'You cannot delete your own account.');
        }

        $this->authorizeTarget($request, $user);

        // Don't allow removing the last administrator — leaves the system unmanageable.
        if ($user->isAdmin()
            && User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])->where('id', '!=', $user->id)->count() === 0) {
            abort(422, 'Refusing to delete the last administrator account.');
        }

        $snapshot = ['id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $user->role];
        $user->delete();

        $this->audit->log('admin.user_deleted', $request->user(), null, $snapshot);

        return response()->json(['message' => 'User deleted.']);
    }

    public function loginEvents(Request $request): JsonResponse
    {
        $events = LoginEvent::query()
            ->with('user:id,name,email')
            ->when($request->filled('only'), function ($q) use ($request) {
                $request->input('only') === 'failed' ? $q->where('successful', false) : $q->where('is_new_ip', true);
            })
            ->latest('created_at')
            ->limit(200)
            ->get();

        return response()->json(['events' => $events]);
    }

    /** Pending password/MFA reset requests for the Admin → Requests inbox. */
    public function resetRequests(Request $request): JsonResponse
    {
        $requests = AccountRequest::query()
            ->with('user:id,name,email,role')
            ->where('status', 'pending')
            ->when(
                ! $request->user()->isSuperAdmin(),
                fn ($q) => $q->whereHas('user', fn ($u) => $u->whereIn('role', [User::ROLE_ANALYST, User::ROLE_VIEWER]))
            )
            ->latest()
            ->get()
            ->filter(fn (AccountRequest $r) => $r->user !== null)
            ->map(fn (AccountRequest $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'created_at' => $r->created_at,
                'user' => [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'email' => $r->user->email,
                    'role' => $r->user->role,
                ],
            ])
            ->values();

        return response()->json(['requests' => $requests]);
    }

    /** Dismiss a request without acting on it. */
    public function dismissRequest(Request $request, AccountRequest $accountRequest): JsonResponse
    {
        if ($accountRequest->user) {
            $this->authorizeTarget($request, $accountRequest->user);
        }

        $accountRequest->forceFill([
            'status' => 'handled',
            'handled_by_user_id' => $request->user()->id,
            'handled_at' => now(),
        ])->save();

        return response()->json(['message' => 'Request dismissed.']);
    }

    /* ------------------------------------------------------------------ */
    /* Dashboard assignment                                                */
    /* ------------------------------------------------------------------ */

    /** All dashboards owned by manage-capable users, available to assign. */
    public function dashboards(Request $request): JsonResponse
    {
        $dashboards = Dashboard::query()
            ->with('user:id,name,email,role')
            ->whereHas('user', function ($q) {
                $q->whereIn('role', [User::ROLE_ADMIN, User::ROLE_ANALYST]);
            })
            ->latest('updated_at')
            ->get()
            ->map(fn (Dashboard $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'is_default' => $d->is_default,
                'owner' => [
                    'id' => $d->user->id,
                    'name' => $d->user->name,
                    'email' => $d->user->email,
                    'role' => $d->user->role,
                ],
                'widget_count' => is_array($d->layout) ? count($d->layout) : 0,
                'updated_at' => $d->updated_at,
            ]);

        return response()->json(['dashboards' => $dashboards]);
    }

    public function userDashboards(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $assigned = $user->assignedDashboards()
            ->with('user:id,name,email')
            ->get()
            ->map(fn (Dashboard $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'owner' => [
                    'id' => $d->user->id,
                    'name' => $d->user->name,
                    'email' => $d->user->email,
                ],
                'assigned_at' => $d->pivot->created_at,
                'assigned_by_user_id' => $d->pivot->assigned_by_user_id,
            ]);

        return response()->json(['dashboards' => $assigned]);
    }

    public function assignDashboard(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);

        $data = $request->validate([
            'dashboard_id' => ['required', 'integer', 'exists:dashboards,id'],
        ]);

        $dashboard = Dashboard::with('user:id,role')->findOrFail($data['dashboard_id']);

        if ($dashboard->user_id === $user->id) {
            abort(422, 'User already owns this dashboard.');
        }

        $alreadyAssigned = $user->assignedDashboards()->whereKey($dashboard->id)->exists();
        if ($alreadyAssigned) {
            abort(422, 'Dashboard is already assigned to this user.');
        }

        $user->assignedDashboards()->attach($dashboard->id, [
            'assigned_by_user_id' => $request->user()->id,
        ]);

        $this->audit->log('dashboard.assigned', $request->user(), $dashboard, [
            'assigned_to_user_id' => $user->id,
            'assigned_to_email' => $user->email,
            'dashboard_name' => $dashboard->name,
            'owner_id' => $dashboard->user_id,
        ]);

        $this->notifications->dispatch('dashboard.assigned', [
            'recipient' => ['name' => $user->name, 'email' => $user->email],
            'dashboard' => ['name' => $dashboard->name],
            'owner' => ['name' => $dashboard->user?->name ?? 'an administrator'],
            'actor' => ['email' => $request->user()->email],
        ], $user);

        return response()->json(['message' => 'Dashboard assigned.']);
    }

    public function unassignDashboard(Request $request, User $user, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $detached = $user->assignedDashboards()->detach($dashboard->id);

        if ($detached === 0) {
            abort(404, 'Dashboard was not assigned to this user.');
        }

        $this->audit->log('dashboard.unassigned', $request->user(), $dashboard, [
            'unassigned_from_user_id' => $user->id,
            'unassigned_from_email' => $user->email,
            'dashboard_name' => $dashboard->name,
        ]);

        return response()->json(['message' => 'Dashboard unassigned.']);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->latest('created_at')
            ->limit(200)
            ->get();

        return response()->json(['logs' => $logs]);
    }

    /** Abort unless the actor strictly outranks the target account. */
    private function authorizeTarget(Request $request, User $target): void
    {
        abort_unless(
            $request->user()->outranks($target),
            403,
            'You cannot manage an account at or above your own role.'
        );
    }

    /** Mark any pending reset requests of this type for the user as handled. */
    private function resolveRequests(User $target, string $type, User $actor): void
    {
        AccountRequest::where('user_id', $target->id)
            ->where('type', $type)
            ->where('status', 'pending')
            ->update([
                'status' => 'handled',
                'handled_by_user_id' => $actor->id,
                'handled_at' => now(),
            ]);
    }

    /** Abort unless the actor may grant the given role (only roles below their own level). */
    private function assertAssignableRole(User $actor, string $role): void
    {
        abort_unless($actor->canAssignRole($role), 422, 'You are not allowed to assign that role.');
    }

    private function randomPassword(): string
    {
        return Str::password(16);
    }

    private function userRow(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'mfa_enabled' => $user->mfa_enabled,
            'mfa_required' => (bool) $user->mfa_required,
            'ip_flagged' => $user->ip_flagged,
            'is_online' => $user->isOnline(),
            'is_locked' => $user->isLocked(),
            'last_login_at' => $user->last_login_at,
            'last_login_ip' => $user->last_login_ip,
            'current_ip' => $user->current_ip,
            'last_seen_at' => $user->last_seen_at,
            'failed_login_attempts' => $user->failed_login_attempts,
            'api_sources_count' => $user->api_sources_count ?? $user->apiSources()->count(),
            'created_at' => $user->created_at,
        ];
    }
}
