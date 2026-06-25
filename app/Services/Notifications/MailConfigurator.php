<?php

namespace App\Services\Notifications;

use App\Models\MailSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Reads SMTP settings from the database at boot and applies them to Laravel's
 * mail config — so admins can configure mail from the UI without touching .env.
 *
 * If the DB row isn't usable (empty, not enabled, table missing), we leave the
 * .env-based defaults in place. That keeps dev environments routed at the log
 * driver until an admin opts in.
 */
class MailConfigurator
{
    public function apply(): void
    {
        try {
            $settings = MailSettings::query()->first();
        } catch (Throwable) {
            return; // tables not migrated yet
        }

        if (! $settings || ! $settings->enabled) {
            return;
        }

        $transport = $settings->transport === 'smtp' ? 'smtp' : 'log';

        Config::set('mail.default', $transport);

        if ($transport === 'smtp') {
            Config::set('mail.mailers.smtp', array_merge(
                Config::get('mail.mailers.smtp', []),
                [
                    'transport' => 'smtp',
                    'host' => $settings->host,
                    'port' => $settings->port,
                    'encryption' => $settings->encryption ?: null,
                    'username' => $settings->username,
                    'password' => $settings->getPassword(),
                    'timeout' => 15,
                ]
            ));
        }

        if ($settings->from_address) {
            Config::set('mail.from', [
                'address' => $settings->from_address,
                'name' => $settings->from_name ?: Config::get('app.name'),
            ]);
        }

        // Reset the mailer container instance so it picks up the new config.
        Mail::purge('smtp');
        Mail::purge('log');
    }
}
