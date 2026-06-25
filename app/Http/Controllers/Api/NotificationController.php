<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailSettings;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notifications\MailConfigurator;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private MailConfigurator $mailConfigurator,
        private AuditLogger $audit,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Mail settings (admin)                                              */
    /* ------------------------------------------------------------------ */

    public function getMailSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $s = MailSettings::active();
        return response()->json(['settings' => $this->serializeMailSettings($s)]);
    }

    public function updateMailSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'transport' => ['required', 'in:smtp,log'],
            'host' => ['nullable', 'string', 'max:200'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', 'in:tls,ssl,none'],
            'username' => ['nullable', 'string', 'max:200'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['nullable', 'email', 'max:200'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email', 'max:200'],
            'enabled' => ['required', 'boolean'],
        ]);

        $settings = MailSettings::active();
        $settings->fill([
            'transport' => $data['transport'],
            'host' => $data['host'] ?? null,
            'port' => $data['port'] ?? null,
            'encryption' => ($data['encryption'] ?? null) === 'none' ? null : ($data['encryption'] ?? null),
            'username' => $data['username'] ?? null,
            'from_address' => $data['from_address'] ?? null,
            'from_name' => $data['from_name'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'enabled' => $data['enabled'],
        ]);

        // Only overwrite password when a value is supplied (empty string = keep).
        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            $settings->setPassword($data['password']);
        }
        $settings->save();

        $this->mailConfigurator->apply();
        $this->audit->log('mail.settings_updated', $request->user(), $settings, [
            'transport' => $settings->transport, 'enabled' => $settings->enabled,
        ]);

        return response()->json(['settings' => $this->serializeMailSettings($settings)]);
    }

    public function testMail(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'to' => ['required', 'email'],
        ]);

        $this->mailConfigurator->apply(); // make sure latest config is loaded

        $settings = MailSettings::active();
        try {
            Mail::raw(
                "This is a test email from EDR Compliance Dashboard.\nSent at ".now()->toDateTimeString(),
                function ($m) use ($data) {
                    $m->to($data['to'])->subject('EDR Compliance — mail configuration test');
                }
            );
            $settings->forceFill([
                'last_test_at' => now(),
                'last_test_status' => 'ok',
                'last_test_error' => null,
            ])->save();

            return response()->json(['ok' => true, 'message' => 'Test email queued via '.config('mail.default').' driver.']);
        } catch (Throwable $e) {
            $settings->forceFill([
                'last_test_at' => now(),
                'last_test_status' => 'failed',
                'last_test_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Templates (admin)                                                  */
    /* ------------------------------------------------------------------ */

    public function listTemplates(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $templates = NotificationTemplate::query()->orderBy('category')->orderBy('event_key')->get();
        $catalog = collect(NotificationCatalog::events())->keyBy('event_key');

        return response()->json([
            'templates' => $templates->map(fn (NotificationTemplate $t) => [
                'id' => $t->id,
                'event_key' => $t->event_key,
                'display_name' => $t->display_name,
                'category' => $t->category,
                'default_severity' => $t->default_severity,
                'subject' => $t->subject,
                'body_html' => $t->body_html,
                'body_text' => $t->body_text,
                'enabled' => $t->enabled,
                'variables' => $catalog[$t->event_key]['variables'] ?? [],
                'default_audience' => $catalog[$t->event_key]['default_audience'] ?? [],
                'updated_at' => $t->updated_at,
            ]),
        ]);
    }

    public function updateTemplate(Request $request, NotificationTemplate $template): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:32000'],
            'body_text' => ['nullable', 'string', 'max:8000'],
            'enabled' => ['required', 'boolean'],
        ]);

        $template->update($data);
        $this->audit->log('notification.template_updated', $request->user(), $template, ['enabled' => $template->enabled]);

        return response()->json(['template' => $template->fresh()]);
    }

    public function resetTemplate(Request $request, NotificationTemplate $template): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $catalog = collect(NotificationCatalog::events())->keyBy('event_key');
        $default = $catalog[$template->event_key] ?? null;
        abort_unless($default, 404);

        $template->update([
            'subject' => $default['subject'],
            'body_html' => $default['body_html'],
            'body_text' => $default['body_text'] ?? null,
            'enabled' => true,
        ]);

        return response()->json(['template' => $template->fresh()]);
    }

    public function previewTemplate(Request $request, NotificationTemplate $template): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $payload = (array) $request->input('payload', $this->samplePayloadFor($template->event_key));
        return response()->json($this->notifications->preview($template, $payload, $request->user()));
    }

    public function testTemplate(Request $request, NotificationTemplate $template): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'to' => ['required', 'email'],
            'payload' => ['nullable', 'array'],
        ]);

        $this->mailConfigurator->apply();
        $payload = (array) ($data['payload'] ?? $this->samplePayloadFor($template->event_key));

        $log = $this->notifications->testSend($template, $data['to'], $payload, $request->user());

        return response()->json([
            'log' => $log,
            'ok' => $log->status === 'sent',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Subscriptions                                                      */
    /* ------------------------------------------------------------------ */

    /** Subscriptions for the requesting user (always allowed). */
    public function mySubscriptions(Request $request): JsonResponse
    {
        return response()->json(['subscriptions' => $this->subscriptionsFor($request->user())]);
    }

    public function updateMySubscriptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscriptions' => ['required', 'array'],
            'subscriptions.*.event_key' => ['required', 'string'],
            'subscriptions.*.enabled' => ['required', 'boolean'],
        ]);
        $this->saveSubscriptions($request->user(), $data['subscriptions']);

        return response()->json(['subscriptions' => $this->subscriptionsFor($request->user())]);
    }

    public function userSubscriptions(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        return response()->json(['subscriptions' => $this->subscriptionsFor($user)]);
    }

    public function updateUserSubscriptions(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'subscriptions' => ['required', 'array'],
            'subscriptions.*.event_key' => ['required', 'string'],
            'subscriptions.*.enabled' => ['required', 'boolean'],
        ]);
        $this->saveSubscriptions($user, $data['subscriptions']);
        $this->audit->log('notification.subscriptions_updated_for_user', $request->user(), $user);

        return response()->json(['subscriptions' => $this->subscriptionsFor($user)]);
    }

    /* ------------------------------------------------------------------ */
    /* Logs (admin)                                                       */
    /* ------------------------------------------------------------------ */

    public function logs(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $logs = NotificationLog::query()
            ->with('user:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('event_key'), fn ($q) => $q->where('event_key', $request->input('event_key')))
            ->latest('created_at')
            ->limit(200)
            ->get();

        return response()->json(['logs' => $logs]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function subscriptionsFor(User $user): array
    {
        $rows = NotificationSubscription::query()
            ->where('user_id', $user->id)
            ->where('channel', 'email')
            ->get()
            ->keyBy('event_key');

        $out = [];
        foreach (NotificationCatalog::events() as $event) {
            $explicit = $rows[$event['event_key']] ?? null;
            $defaultOn = in_array($user->role, $event['default_audience'], true);
            $out[] = [
                'event_key' => $event['event_key'],
                'display_name' => $event['display_name'],
                'category' => $event['category'],
                'default_severity' => $event['default_severity'],
                'enabled' => $explicit ? (bool) $explicit->enabled : $defaultOn,
                'is_explicit' => (bool) $explicit,
                'default_on' => $defaultOn,
            ];
        }
        return $out;
    }

    private function saveSubscriptions(User $user, array $items): void
    {
        $catalogKeys = collect(NotificationCatalog::events())->pluck('event_key')->all();
        foreach ($items as $item) {
            if (! in_array($item['event_key'], $catalogKeys, true)) {
                continue;
            }
            NotificationSubscription::updateOrCreate(
                ['user_id' => $user->id, 'event_key' => $item['event_key'], 'channel' => 'email'],
                ['enabled' => (bool) $item['enabled']]
            );
        }
    }

    private function serializeMailSettings(MailSettings $s): array
    {
        return [
            'id' => $s->id,
            'transport' => $s->transport,
            'host' => $s->host,
            'port' => $s->port,
            'encryption' => $s->encryption,
            'username' => $s->username,
            'has_password' => $s->hasPassword(),
            'from_address' => $s->from_address,
            'from_name' => $s->from_name,
            'reply_to' => $s->reply_to,
            'enabled' => $s->enabled,
            'last_test_at' => $s->last_test_at,
            'last_test_status' => $s->last_test_status,
            'last_test_error' => $s->last_test_error,
        ];
    }

    /** Sample payload per event, used by preview/test when caller doesn't supply one. */
    private function samplePayloadFor(string $eventKey): array
    {
        return match ($eventKey) {
            'login.new_ip' => [
                'user' => ['name' => 'Alice Example', 'email' => 'alice@example.com'],
                'ip' => '203.0.113.42', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130',
                'when' => now()->toDateTimeString(),
            ],
            'login.failed_threshold' => [
                'email' => 'attacker@example.com', 'count' => 6,
                'ip' => '198.51.100.7', 'when' => now()->toDateTimeString(),
            ],
            'account.locked' => [
                'user' => ['name' => 'Alice Example', 'email' => 'alice@example.com'],
                'until' => now()->addMinutes(15)->toDateTimeString(),
                'reason' => 'Too many failed sign-in attempts',
            ],
            'account.mfa_disabled' => [
                'user' => ['email' => 'alice@example.com'],
                'actor' => ['email' => 'admin@compliance.local'],
                'when' => now()->toDateTimeString(),
            ],
            'account.password_reset' => [
                'user' => ['email' => 'alice@example.com', 'name' => 'Alice Example'],
                'actor' => ['email' => 'admin@compliance.local'],
            ],
            'account.role_changed' => [
                'user' => ['email' => 'alice@example.com'],
                'old_role' => 'viewer', 'new_role' => 'analyst',
                'actor' => ['email' => 'admin@compliance.local'],
            ],
            'dashboard.assigned' => [
                'recipient' => ['name' => 'Alice Example', 'email' => 'alice@example.com'],
                'dashboard' => ['name' => 'Quarterly Compliance Review'],
                'owner' => ['name' => 'Security Admin'],
                'actor' => ['email' => 'admin@compliance.local'],
            ],
            'source.refresh_failed' => [
                'source' => ['name' => 'Corp CrowdStrike', 'vendor' => 'crowdstrike'],
                'error' => 'HTTP 401: invalid_token',
                'when' => now()->toDateTimeString(),
            ],
            'source.refresh_recovered' => [
                'source' => ['name' => 'Corp CrowdStrike'],
                'when' => now()->toDateTimeString(),
            ],
            'vuln.new_advisory' => [
                'package' => 'postcss', 'version' => '8.5.15', 'ecosystem' => 'npm',
                'cve' => 'GHSA-QX2V-QP2M-JG93', 'severity' => 'moderate',
                'title' => 'PostCSS has XSS via Unescaped </style> in its CSS Stringify Output',
                'url' => 'https://github.com/advisories/GHSA-qx2v-qp2m-jg93',
            ],
            default => [],
        };
    }
}
