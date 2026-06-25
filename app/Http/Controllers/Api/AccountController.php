<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountRequest;
use App\Services\Notifications\NotificationService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * A non-admin user asks an administrator to reset their password or MFA.
     * Recorded in the audit log and (best-effort) emailed to admins.
     */
    public function requestReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['password', 'mfa'])],
        ]);

        $user = $request->user();

        // Record (dedupe an existing pending request of the same type) so admins
        // get a clear, actionable item in the Admin → Requests inbox.
        $req = AccountRequest::firstOrCreate(
            ['user_id' => $user->id, 'type' => $data['type'], 'status' => 'pending'],
        );

        $this->audit->log('account.reset_requested', $user, $req, ['type' => $data['type']]);

        try {
            $frontend = rtrim((string) config('security.frontend_url'), '/');
            $this->notifications->dispatch('account.reset_requested', [
                'user' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
                'type' => $data['type'],
                'when' => now()->toDateTimeString(),
                'link' => $frontend.'/admin?user='.$user->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Your request has been sent to the administrators.',
        ]);
    }
}
