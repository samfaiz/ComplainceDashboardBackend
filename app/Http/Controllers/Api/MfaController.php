<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Security\AuditLogger;
use App\Services\Security\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MfaController extends Controller
{
    public function __construct(private MfaService $mfa, private AuditLogger $audit) {}

    /** Begin enrollment: issue a secret + otpauth URL (QR rendered client-side). */
    public function setup(Request $request): JsonResponse
    {
        $this->ensureCanSelfEnroll($request);

        $secret = $this->mfa->generateSecret();
        $request->session()->put('mfa.setup_secret', $secret);

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->mfa->provisioningUri($request->user()->email, $secret),
        ]);
    }

    /** Confirm enrollment with a code and return one-time recovery codes. */
    public function confirm(Request $request): JsonResponse
    {
        $this->ensureCanSelfEnroll($request);

        $data = $request->validate(['code' => ['required', 'string']]);

        $secret = $request->session()->get('mfa.setup_secret');
        if (! $secret) {
            throw ValidationException::withMessages(['code' => ['Start MFA setup first.']]);
        }

        if (! $this->mfa->verifyCode($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['Invalid code — check your authenticator app clock.']]);
        }

        $codes = $this->mfa->enable($request->user(), $secret);
        $request->session()->forget('mfa.setup_secret');
        $this->audit->log('mfa.enabled', $request->user());

        return response()->json(['enabled' => true, 'recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        // Only admins can self-disable. Everyone else must ask an admin to reset.
        abort_unless($request->user()->isAdmin(), 403, 'Only an administrator can disable MFA for you.');

        $this->requirePassword($request);
        $this->mfa->disable($request->user());
        $this->audit->log('mfa.disabled', $request->user());

        return response()->json(['enabled' => false]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->ensureCanSelfEnroll($request);

        $this->requirePassword($request);
        $codes = $this->mfa->regenerateRecoveryCodes($request->user());
        $this->audit->log('mfa.recovery_codes_regenerated', $request->user());

        return response()->json(['recovery_codes' => $codes]);
    }

    /**
     * Self-enrollment is restricted to admins and to users an admin has
     * explicitly flagged via mfa_required = true. Everyone else gets 403.
     */
    private function ensureCanSelfEnroll(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->mfa_required,
            403,
            'MFA enrollment is controlled by your administrator.'
        );
    }

    private function requirePassword(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['Incorrect password.']]);
        }
    }
}
