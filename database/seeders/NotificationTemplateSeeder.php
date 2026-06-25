<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (NotificationCatalog::events() as $event) {
            NotificationTemplate::updateOrCreate(
                ['event_key' => $event['event_key']],
                [
                    'display_name' => $event['display_name'],
                    'category' => $event['category'],
                    'default_severity' => $event['default_severity'],
                    'subject' => $event['subject'],
                    'body_html' => $event['body_html'],
                    'body_text' => $event['body_text'] ?? null,
                    'enabled' => true,
                ]
            );
        }
    }
}
