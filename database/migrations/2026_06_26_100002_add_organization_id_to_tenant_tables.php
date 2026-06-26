<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** table => column to place organization_id after. */
    private array $tables = [
        'api_sources' => 'user_id',
        'sites' => 'user_id',
        'dashboards' => 'user_id',
        'snapshots' => 'api_source_id',
        'source_runs' => 'api_source_id',
        'endpoints' => 'api_source_id',
        'notification_subscriptions' => 'user_id',
        'notification_logs' => 'user_id',
        'audit_logs' => 'user_id',
        'login_events' => 'user_id',
        'account_requests' => 'user_id',
        'known_ips' => 'user_id',
        'endpoint_column_layouts' => 'user_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $after) {
            Schema::table($table, function (Blueprint $t) use ($after) {
                $t->foreignId('organization_id')->nullable()->after($after)
                    ->constrained()->cascadeOnDelete();
            });
        }

        // Mail settings: one row per organization.
        Schema::table('mail_settings', function (Blueprint $t) {
            $t->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $t->unique('organization_id');
        });

        // Notification templates: each org owns its own template set, so the
        // global unique(event_key) becomes unique(organization_id, event_key).
        Schema::table('notification_templates', function (Blueprint $t) {
            $t->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });
        Schema::table('notification_templates', function (Blueprint $t) {
            $t->dropUnique(['event_key']);
            $t->unique(['organization_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $t) {
            $t->dropUnique(['organization_id', 'event_key']);
            $t->dropConstrainedForeignId('organization_id');
            $t->unique('event_key');
        });

        Schema::table('mail_settings', function (Blueprint $t) {
            $t->dropUnique(['organization_id']);
            $t->dropConstrainedForeignId('organization_id');
        });

        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('organization_id');
            });
        }
    }
};
