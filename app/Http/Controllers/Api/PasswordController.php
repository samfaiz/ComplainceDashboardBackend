<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Security\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        // Analysts/Viewers can't self-change their password (they request an admin
        // reset) — except to satisfy a forced change after an admin-issued reset.
        if (! $user->isAdmin() && ! $user->must_change_password) {
            abort(403, 'Self-service password changes are disabled for your role. Please request an administrator to reset it.');
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        $this->audit->log('password.changed', $user);

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
