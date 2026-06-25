<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Canonical list of every notification this app can send.
 *
 * Each entry defines:
 *  - event_key:          machine identifier (snake_case)
 *  - display_name:       label shown in admin UI
 *  - category:           grouping in UI (security / account / dashboard / source / vulnerability)
 *  - default_severity:   info | warning | critical
 *  - variables:          map of {{ placeholder }} → human description (for editor hints)
 *  - default_audience:   which roles get this by default (admin / analyst / viewer)
 *  - subject / body_html / body_text: starter content (admin can edit per template)
 */
class NotificationCatalog
{
    /** @return array<int, array<string, mixed>> */
    public static function events(): array
    {
        return [
            // ---------------- security / login ----------------
            [
                'event_key' => 'login.new_ip',
                'display_name' => 'Sign-in from new IP',
                'category' => 'security',
                'default_severity' => 'warning',
                'variables' => [
                    'user.name' => 'Name of the signing-in user',
                    'user.email' => 'Their email',
                    'ip' => 'IP address of the new sign-in',
                    'user_agent' => 'Browser / device user agent string',
                    'when' => 'Timestamp of the sign-in',
                ],
                'default_audience' => [User::ROLE_ADMIN],
                'subject' => 'New sign-in location for {{ user.email }}',
                'body_html' => self::wrap('<h2>New sign-in detected</h2>
<p><strong>{{ user.name }}</strong> ({{ user.email }}) signed in from an IP address not seen before:</p>
<table>
  <tr><th>IP</th><td>{{ ip }}</td></tr>
  <tr><th>Device</th><td>{{ user_agent }}</td></tr>
  <tr><th>When</th><td>{{ when }}</td></tr>
</table>
<p>If this wasn\'t expected, lock the account from the Admin panel.</p>'),
                'body_text' => "New sign-in for {{ user.email }} from {{ ip }} at {{ when }}.\nDevice: {{ user_agent }}",
            ],
            [
                'event_key' => 'login.failed_threshold',
                'display_name' => 'Repeated failed sign-ins',
                'category' => 'security',
                'default_severity' => 'critical',
                'variables' => [
                    'email' => 'Email that was attempted',
                    'count' => 'Number of failed attempts',
                    'ip' => 'IP address',
                    'when' => 'Most recent attempt',
                ],
                'default_audience' => [User::ROLE_ADMIN],
                'subject' => '{{ count }} failed sign-ins for {{ email }}',
                'body_html' => self::wrap('<h2>Repeated failed sign-ins</h2>
<p>There have been <strong>{{ count }}</strong> failed sign-in attempts for <strong>{{ email }}</strong> from <code>{{ ip }}</code> ending at {{ when }}.</p>
<p>If this is unexpected, consider locking the account.</p>'),
                'body_text' => "{{ count }} failed sign-ins for {{ email }} from {{ ip }} (last: {{ when }}).",
            ],
            [
                'event_key' => 'account.locked',
                'display_name' => 'Account locked',
                'category' => 'security',
                'default_severity' => 'warning',
                'variables' => [
                    'user.name' => 'Account name',
                    'user.email' => 'Account email',
                    'until' => 'When the lock expires',
                    'reason' => 'Why it was locked',
                ],
                'default_audience' => [User::ROLE_ADMIN],
                'subject' => 'Account locked: {{ user.email }}',
                'body_html' => self::wrap('<h2>Account locked</h2>
<p>The account for <strong>{{ user.name }}</strong> ({{ user.email }}) has been locked until <strong>{{ until }}</strong>.</p>
<p>Reason: {{ reason }}</p>'),
                'body_text' => "{{ user.email }} locked until {{ until }} ({{ reason }}).",
            ],
            [
                'event_key' => 'account.mfa_disabled',
                'display_name' => 'MFA disabled',
                'category' => 'account',
                'default_severity' => 'warning',
                'variables' => [
                    'user.email' => 'Account whose MFA was turned off',
                    'actor.email' => 'Who disabled it',
                    'when' => 'When',
                ],
                'default_audience' => [User::ROLE_ADMIN],
                'subject' => 'MFA disabled for {{ user.email }}',
                'body_html' => self::wrap('<h2>Multi-factor authentication disabled</h2>
<p>MFA was turned off for <strong>{{ user.email }}</strong> by {{ actor.email }} at {{ when }}.</p>'),
                'body_text' => "MFA disabled for {{ user.email }} by {{ actor.email }} at {{ when }}.",
            ],
            [
                'event_key' => 'account.password_reset',
                'display_name' => 'Admin reset your password',
                'category' => 'account',
                'default_severity' => 'info',
                'variables' => [
                    'user.email' => 'Recipient',
                    'actor.email' => 'Admin who performed the reset',
                ],
                'default_audience' => [User::ROLE_ADMIN, User::ROLE_ANALYST, User::ROLE_VIEWER],
                'subject' => 'Your password was reset by an administrator',
                'body_html' => self::wrap('<h2>Your password has been reset</h2>
<p>An administrator ({{ actor.email }}) reset the password for your account ({{ user.email }}). You will be required to choose a new password on your next sign-in.</p>
<p>If you did not request this, contact your security team immediately.</p>'),
                'body_text' => "Your password was reset by {{ actor.email }}. You'll be required to change it on next sign-in.",
            ],
            [
                'event_key' => 'account.role_changed',
                'display_name' => 'Role changed',
                'category' => 'account',
                'default_severity' => 'info',
                'variables' => [
                    'user.email' => 'Account whose role changed',
                    'old_role' => 'Previous role',
                    'new_role' => 'New role',
                    'actor.email' => 'Admin who made the change',
                ],
                'default_audience' => [User::ROLE_ADMIN],
                'subject' => 'Role change: {{ user.email }} → {{ new_role }}',
                'body_html' => self::wrap('<h2>User role updated</h2>
<p>{{ actor.email }} changed the role of <strong>{{ user.email }}</strong> from <code>{{ old_role }}</code> to <code>{{ new_role }}</code>.</p>'),
                'body_text' => "{{ user.email }} changed from {{ old_role }} to {{ new_role }} by {{ actor.email }}.",
            ],

            // ---------------- dashboards ----------------
            [
                'event_key' => 'dashboard.assigned',
                'display_name' => 'Dashboard assigned to you',
                'category' => 'dashboard',
                'default_severity' => 'info',
                'variables' => [
                    'recipient.name' => 'User receiving the dashboard',
                    'dashboard.name' => 'Dashboard name',
                    'owner.name' => 'Owner of the dashboard',
                    'actor.email' => 'Admin who assigned it',
                ],
                'default_audience' => [User::ROLE_ADMIN, User::ROLE_ANALYST, User::ROLE_VIEWER],
                'subject' => 'You\'ve been assigned the dashboard "{{ dashboard.name }}"',
                'body_html' => self::wrap('<h2>Dashboard assigned</h2>
<p>Hi {{ recipient.name }}, {{ actor.email }} has shared the dashboard <strong>{{ dashboard.name }}</strong> (created by {{ owner.name }}) with you. Sign in to view it.</p>'),
                'body_text' => "Dashboard '{{ dashboard.name }}' assigned to you by {{ actor.email }}.",
            ],

            // ---------------- sources / connectors ----------------
            [
                'event_key' => 'source.refresh_failed',
                'display_name' => 'Source refresh failed',
                'category' => 'source',
                'default_severity' => 'warning',
                'variables' => [
                    'source.name' => 'Connector name',
                    'source.vendor' => 'Vendor / type',
                    'error' => 'Error message',
                    'when' => 'When it failed',
                ],
                'default_audience' => [User::ROLE_ADMIN, User::ROLE_ANALYST],
                'subject' => 'Connector "{{ source.name }}" failed to refresh',
                'body_html' => self::wrap('<h2>Source refresh failed</h2>
<p>The connector <strong>{{ source.name }}</strong> ({{ source.vendor }}) failed to refresh at {{ when }}.</p>
<pre style="background:#f4f4f5;padding:8px;border-radius:6px;">{{ error }}</pre>'),
                'body_text' => "Source {{ source.name }} failed at {{ when }}: {{ error }}",
            ],
            [
                'event_key' => 'source.refresh_recovered',
                'display_name' => 'Source refresh recovered',
                'category' => 'source',
                'default_severity' => 'info',
                'variables' => [
                    'source.name' => 'Connector name',
                    'when' => 'When it recovered',
                ],
                'default_audience' => [User::ROLE_ADMIN, User::ROLE_ANALYST],
                'subject' => 'Connector "{{ source.name }}" is healthy again',
                'body_html' => self::wrap('<h2>Source recovered</h2>
<p>The connector <strong>{{ source.name }}</strong> started succeeding again at {{ when }}.</p>'),
                'body_text' => "Source {{ source.name }} recovered at {{ when }}.",
            ],

            // ---------------- vulnerabilities ----------------
            [
                'event_key' => 'vuln.new_advisory',
                'display_name' => 'New CVE for installed package',
                'category' => 'vulnerability',
                'default_severity' => 'critical',
                'variables' => [
                    'package' => 'Package name',
                    'version' => 'Installed version',
                    'ecosystem' => 'php or npm',
                    'cve' => 'CVE / GHSA identifier',
                    'severity' => 'critical / high / moderate / low',
                    'title' => 'Advisory title',
                    'url' => 'Link to the advisory',
                ],
                'default_audience' => [User::ROLE_ADMIN],
                'subject' => '[{{ severity }}] {{ cve }} affects {{ package }} {{ version }}',
                'body_html' => self::wrap('<h2>New vulnerability detected</h2>
<p>A new advisory affects an installed package in this environment.</p>
<table>
  <tr><th>Package</th><td>{{ ecosystem }} <strong>{{ package }}</strong> {{ version }}</td></tr>
  <tr><th>Identifier</th><td>{{ cve }}</td></tr>
  <tr><th>Severity</th><td>{{ severity }}</td></tr>
  <tr><th>Summary</th><td>{{ title }}</td></tr>
</table>
<p><a href="{{ url }}">View advisory</a></p>'),
                'body_text' => "{{ cve }} ({{ severity }}) — {{ package }} {{ version }} — {{ title }}\n{{ url }}",
            ],
        ];
    }

    public static function variablesFor(string $eventKey): array
    {
        foreach (self::events() as $e) {
            if ($e['event_key'] === $eventKey) {
                return $e['variables'];
            }
        }
        return [];
    }

    public static function defaultAudienceFor(string $eventKey): array
    {
        foreach (self::events() as $e) {
            if ($e['event_key'] === $eventKey) {
                return $e['default_audience'];
            }
        }
        return [];
    }

    /** Standard HTML wrapper (header + footer) for all emails. */
    private static function wrap(string $inner): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0b1220;padding:24px 12px;font-family:-apple-system,Segoe UI,Roboto,sans-serif;">
<tr><td align="center">
<table width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;padding:24px;color:#0b1220;">
<tr><td style="border-bottom:1px solid #e4e4e7;padding-bottom:12px;margin-bottom:16px;">
<strong style="color:#2563eb;">EDR Compliance</strong> · <span style="color:#52525b;">{{ app.name }}</span>
</td></tr>
<tr><td style="padding-top:16px;">{$inner}</td></tr>
<tr><td style="padding-top:16px;border-top:1px solid #e4e4e7;color:#71717a;font-size:11px;">
This is an automated message. You're receiving it because you're subscribed to <code>{{ event_key }}</code> notifications. Manage your subscriptions in Settings → Notifications.
</td></tr>
</table></td></tr></table>
HTML;
    }
}
