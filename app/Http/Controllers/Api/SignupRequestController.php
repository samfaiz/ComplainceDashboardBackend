<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\GenericNotificationMail;
use App\Models\MailSettings;
use App\Models\SignupRequest;
use App\Models\User;
use App\Services\Notifications\MailConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

class SignupRequestController extends Controller
{
    public function __construct(private MailConfigurator $mailConfigurator) {}

    /** Public: a prospect asks an admin for an account. Rate-limited on the route. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:200'],
            'account_type' => ['required', Rule::in(SignupRequest::TYPES)],
            'organization' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $signup = SignupRequest::create([
            ...$data,
            'status' => 'new',
            'ip_address' => $request->ip(),
        ]);

        $this->notifyPlatformOwner($signup);

        return response()->json([
            'message' => "Thanks — your request has been sent. An administrator will reach out shortly.",
        ], 201);
    }

    /** Platform owner: list incoming account requests. */
    public function index(): JsonResponse
    {
        return response()->json([
            'requests' => SignupRequest::latest()->limit(200)->get(),
        ]);
    }

    /**
     * Email the platform owner about a new account request, using the platform
     * SMTP config. Best-effort: a mail failure must never break the public request.
     */
    private function notifyPlatformOwner(SignupRequest $signup): void
    {
        try {
            $settings = MailSettings::platform();
            $to = $settings->notify_email
                ?: User::withoutOrganizationScope()->where('role', 'super_admin')->value('email');

            if (! $to) {
                return; // nowhere to send
            }

            $this->mailConfigurator->applyPlatform();

            $typeLabel = ucfirst($signup->account_type);
            $rows = [
                'Name' => e($signup->name),
                'Email' => e($signup->email),
                'Type' => e($typeLabel),
                'Organization' => e($signup->organization ?: '—'),
                'Message' => nl2br(e($signup->message ?: '—')),
                'IP address' => e($signup->ip_address ?: '—'),
            ];

            $html = '<h2 style="margin:0 0 12px">New account request</h2>'
                .'<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px">';
            foreach ($rows as $label => $value) {
                $html .= '<tr>'
                    .'<td style="padding:4px 12px 4px 0;color:#64748b;vertical-align:top"><strong>'.$label.'</strong></td>'
                    .'<td style="padding:4px 0">'.$value.'</td></tr>';
            }
            $html .= '</table><p style="margin-top:16px;color:#64748b;font-size:13px">'
                .'Review it under Platform &rarr; Account requests to provision an organization.</p>';

            $text = "New account request\n"
                ."Name: {$signup->name}\nEmail: {$signup->email}\nType: {$typeLabel}\n"
                ."Organization: ".($signup->organization ?: '—')."\n"
                ."Message: ".($signup->message ?: '—')."\n"
                ."IP: ".($signup->ip_address ?: '—');

            Mail::to($to)->send(new GenericNotificationMail(
                "New account request from {$signup->name}", $html, $text,
            ));
        } catch (Throwable $e) {
            Log::warning('Failed to notify platform owner of signup request', [
                'signup_request_id' => $signup->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
