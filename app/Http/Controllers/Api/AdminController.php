<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginEvent;
use App\Models\User;
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
    public function __construct(private AuditLogger $audit, private MfaService $mfa) {}

    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->withCount('apiSources')
            ->latest()
            ->get()
            ->map(fn (User $u) => $this->userRow($u));

        return response()->json(['users' => $users]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
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

        // Prevent an admin from locking themselves out of the admin role.
        if (array_key_exists('role', $data) && $user->id === $request->user()->id && $data['role'] !== User::ROLE_ADMIN) {
            abort(422, 'You cannot remove your own admin role.');
        }

        $user->update($data);
        $this->audit->log('admin.user_updated', $request->user(), $user, $data);

        return response()->json(['user' => $this->userRow($user->fresh())]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
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

        return response()->json([
            'message' => 'Password reset. The user must change it on next login.',
            'temporary_password' => $generated ? $password : null,
        ]);
    }

    public function clearIpFlag(Request $request, User $user): JsonResponse
    {
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
        $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0])->save();
        $this->audit->log('admin.user_unlocked', $request->user(), $user);

        return response()->json(['user' => $this->userRow($user->fresh())]);
    }

    public function disableMfa(Request $request, User $user): JsonResponse
    {
        $this->mfa->disable($user);
        $this->audit->log('admin.mfa_disabled', $request->user(), $user);

        return response()->json(['user' => $this->userRow($user->fresh())]);
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
