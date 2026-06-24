<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based One-Time Password (TOTP, RFC 6238) MFA — compatible with Google
 * Authenticator, Authy, 1Password, etc. The shared secret is stored encrypted
 * (AES via APP_KEY) and recovery codes are stored hashed (Argon2id).
 */
class MfaService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA();
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** otpauth:// URL the frontend renders as a QR code. */
    public function provisioningUri(string $holder, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            (string) config('app.name', 'EDR Compliance'),
            $holder,
            $secret
        );
    }

    public function verifyCode(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if ($code === null || $code === '') {
            return false;
        }

        return (bool) $this->engine->verifyKey($secret, $code);
    }

    /* ------------------------------------------------------------------ */
    /* Persisted-secret helpers                                            */
    /* ------------------------------------------------------------------ */

    public function enable(User $user, string $secret): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_secret' => Crypt::encryptString($secret),
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $codes)),
        ])->save();

        return $codes; // shown to the user exactly once
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();
    }

    public function verifyForUser(User $user, string $code): bool
    {
        if (! $user->mfa_enabled || ! $user->mfa_secret) {
            return false;
        }

        return $this->verifyCode(Crypt::decryptString($user->mfa_secret), $code);
    }

    /** Verify and consume a one-time recovery code. */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = json_decode($user->mfa_recovery_codes ?? '[]', true) ?: [];
        $code = trim($code);

        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['mfa_recovery_codes' => json_encode(array_values($hashes))])->save();

                return true;
            }
        }

        return false;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();
        $user->forceFill([
            'mfa_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $codes)),
        ])->save();

        return $codes;
    }

    /** @return array<int, string> */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
