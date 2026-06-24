<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\Security\LoginSecurityService;
use App\Services\Security\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private LoginSecurityService $loginSecurity,
        private MfaService $mfa,
        private AuditLogger $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && $user->isLocked()) {
            $this->loginSecurity->registerFailure($user, $data['email'], $request, 'locked');
            throw ValidationException::withMessages([
                'email' => ['Account temporarily locked after too many failed attempts. Try again later.'],
            ]);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->loginSecurity->registerFailure($user, $data['email'], $request, 'bad_credentials');
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            $this->loginSecurity->registerFailure($user, $data['email'], $request, 'inactive');
            throw ValidationException::withMessages([
                'email' => ['This account has been disabled. Contact an administrator.'],
            ]);
        }

        // Transparently upgrade the stored hash if the work factor changed.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($data['password'])])->save();
        }

        if ($user->mfa_enabled) {
            $request->session()->put('mfa.user_id', $user->id);
            $request->session()->put('mfa.remember', (bool) ($data['remember'] ?? false));
            $request->session()->put('mfa.expires_at', now()->addMinutes(5)->timestamp);

            return response()->json(['mfa_required' => true]);
        }

        return $this->completeLogin($user, $request, (bool) ($data['remember'] ?? false));
    }

    public function loginMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $userId = $request->session()->get('mfa.user_id');
        $expiresAt = $request->session()->get('mfa.expires_at');

        if (! $userId || ! $expiresAt || now()->timestamp > $expiresAt) {
            $request->session()->forget(['mfa.user_id', 'mfa.remember', 'mfa.expires_at']);
            throw ValidationException::withMessages([
                'code' => ['Your verification window expired. Please sign in again.'],
            ]);
        }

        $user = User::find($userId);
        abort_unless($user !== null, 401);

        $ok = false;
        if (! empty($data['code'])) {
            $ok = $this->mfa->verifyForUser($user, $data['code']);
        }
        if (! $ok && ! empty($data['recovery_code'])) {
            $ok = $this->mfa->consumeRecoveryCode($user, $data['recovery_code']);
        }

        if (! $ok) {
            $this->loginSecurity->registerFailure($user, $user->email, $request, 'mfa_failed');
            throw ValidationException::withMessages(['code' => ['Invalid verification code.']]);
        }

        $remember = (bool) $request->session()->get('mfa.remember', false);
        $request->session()->forget(['mfa.user_id', 'mfa.remember', 'mfa.expires_at']);

        return $this->completeLogin($user, $request, $remember);
    }

    private function completeLogin(User $user, Request $request, bool $remember): JsonResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        $isNewIp = $this->loginSecurity->registerSuccess($user, $request);

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
            'new_ip' => $isNewIp,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            $this->audit->log('auth.logout', $user);
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public static function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'is_admin' => $user->isAdmin(),
            'can_manage' => $user->canManage(),
            'mfa_enabled' => $user->mfa_enabled,
            'ip_flagged' => $user->ip_flagged,
            'must_change_password' => $user->must_change_password,
            'current_ip' => $user->current_ip,
            'last_login_at' => $user->last_login_at,
            'preferences' => $user->preferences,
        ];
    }
}
