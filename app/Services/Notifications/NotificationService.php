<?php

namespace App\Services\Notifications;

use App\Mail\GenericNotificationMail;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Central email dispatcher.
 *
 * Flow:
 *   1. Look up the template for $eventKey. Skip when disabled or missing.
 *   2. Resolve subscribers: every active user whose effective subscription
 *      for this event is enabled. Effective = explicit row OR role default.
 *   3. Render subject + body per recipient with their personal context merged in.
 *   4. Send (mail), then log to notification_logs (sent | failed | skipped).
 *
 * Sending is intentionally synchronous + best-effort. Failures are logged but
 * don't bubble — a broken SMTP shouldn't break a login flow.
 */
class NotificationService
{
    public function __construct(
        private TemplateRenderer $renderer,
        private Tenancy $tenancy,
        private MailConfigurator $mailConfigurator,
    ) {}

    /**
     * Dispatch an event. $payload values are exposed to templates as {{ key }}.
     *
     * The organization is taken from the target user, the explicit argument, or
     * the active tenant context (in that order). With no organization we send
     * nothing — that prevents an event with no clear tenant from broadcasting
     * across organizations.
     */
    public function dispatch(string $eventKey, array $payload = [], ?User $targetUser = null, ?int $organizationId = null): void
    {
        $orgId = $targetUser?->organization_id ?? $organizationId ?? $this->tenancy->id();
        if ($orgId === null) {
            return;
        }

        $this->tenancy->runFor($orgId, function () use ($eventKey, $payload, $targetUser, $orgId) {
            $template = NotificationTemplate::forEvent($eventKey);
            if (! $template || ! $template->enabled) {
                return;
            }

            // Load this organization's SMTP config before sending.
            $this->mailConfigurator->apply($orgId);

            $recipients = $this->resolveRecipients($eventKey, $targetUser);

            // Merge a few app-wide values every template can use.
            $base = array_merge($payload, [
                'event_key' => $eventKey,
                'app' => ['name' => config('app.name'), 'url' => config('app.url')],
            ]);

            foreach ($recipients as $user) {
                $this->sendOne($template, $user, $base);
            }
        });
    }

    /** Render a template against a payload — used by preview/test endpoints. */
    public function preview(NotificationTemplate $template, array $payload, ?User $for = null): array
    {
        $base = array_merge($payload, [
            'event_key' => $template->event_key,
            'app' => ['name' => config('app.name'), 'url' => config('app.url')],
            'recipient' => $for ? [
                'name' => $for->name,
                'email' => $for->email,
            ] : ['name' => 'Recipient Name', 'email' => 'recipient@example.com'],
        ]);

        return [
            'subject' => $this->renderer->render($template->subject, $base),
            'html' => $this->renderer->render($template->body_html, $base),
            'text' => $template->body_text ? $this->renderer->render($template->body_text, $base) : null,
        ];
    }

    /** Test send: render template, send to a single email, log it. */
    public function testSend(NotificationTemplate $template, string $recipientEmail, array $payload, ?User $actor = null): NotificationLog
    {
        // Use the current organization's SMTP config (admin acts within their org).
        $this->mailConfigurator->apply();

        $rendered = $this->preview($template, $payload, $actor);

        $log = NotificationLog::create([
            'user_id' => $actor?->id,
            'event_key' => $template->event_key,
            'channel' => 'email',
            'recipient' => $recipientEmail,
            'subject' => '[TEST] '.$rendered['subject'],
            'status' => 'queued',
            'payload' => ['test' => true, 'payload' => $payload],
        ]);

        try {
            Mail::to($recipientEmail)->send(
                new GenericNotificationMail('[TEST] '.$rendered['subject'], $rendered['html'], $rendered['text'])
            );
            $log->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning('notification test send failed', ['event' => $template->event_key, 'error' => $e->getMessage()]);
        }

        return $log->refresh();
    }

    /** @return array<int, User> */
    private function resolveRecipients(string $eventKey, ?User $targetUser): array
    {
        // Some events have a clear single recipient (the target user). For those,
        // we still respect their personal subscription preference.
        if ($targetUser) {
            return $this->isSubscribed($targetUser, $eventKey) ? [$targetUser] : [];
        }

        $defaultRoles = NotificationCatalog::defaultAudienceFor($eventKey);

        $users = User::query()->where('is_active', true)->get();

        return $users->filter(fn (User $u) => $this->isSubscribed($u, $eventKey, $defaultRoles))->values()->all();
    }

    private function isSubscribed(User $user, string $eventKey, ?array $defaultRoles = null): bool
    {
        $sub = NotificationSubscription::query()
            ->where('user_id', $user->id)
            ->where('event_key', $eventKey)
            ->where('channel', 'email')
            ->first();

        if ($sub) {
            return (bool) $sub->enabled;
        }

        $defaultRoles ??= NotificationCatalog::defaultAudienceFor($eventKey);
        return in_array($user->role, $defaultRoles, true);
    }

    private function sendOne(NotificationTemplate $template, User $user, array $base): void
    {
        if (! $user->email) {
            return;
        }

        $payload = array_merge($base, [
            'recipient' => ['name' => $user->name, 'email' => $user->email],
        ]);

        $subject = $this->renderer->render($template->subject, $payload);
        $html = $this->renderer->render($template->body_html, $payload);
        $text = $template->body_text ? $this->renderer->render($template->body_text, $payload) : null;

        $log = NotificationLog::create([
            'user_id' => $user->id,
            'event_key' => $template->event_key,
            'channel' => 'email',
            'recipient' => $user->email,
            'subject' => $subject,
            'status' => 'queued',
            'payload' => $payload,
        ]);

        try {
            Mail::to($user->email)->send(new GenericNotificationMail($subject, $html, $text));
            $log->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning('notification send failed', [
                'event' => $template->event_key,
                'to' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
