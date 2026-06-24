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
        $this->requirePassword($request);
        $this->mfa->disable($request->user());
        $this->audit->log('mfa.disabled', $request->user());

        return response()->json(['enabled' => false]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->requirePassword($request);
        $codes = $this->mfa->regenerateRecoveryCodes($request->user());
        $this->audit->log('mfa.recovery_codes_regenerated', $request->user());

        return response()->json(['recovery_codes' => $codes]);
    }

    private function requirePassword(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['Incorrect password.']]);
        }
    }
}
