<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tables = [
        'api_sources', 'sites', 'dashboards', 'snapshots', 'source_runs', 'endpoints',
        'notification_subscriptions', 'notification_logs', 'audit_logs', 'login_events',
        'account_requests', 'known_ips', 'endpoint_column_layouts', 'mail_settings',
        'notification_templates',
    ];

    public function up(): void
    {
        // Only migrate pre-existing single-tenant data: org-bound users that have
        // no organization yet. Fresh installs are seeder-driven and skip this.
        $needs = DB::table('users')
            ->where('role', '!=', 'super_admin')
            ->whereNull('organization_id')
            ->exists();

        if (! $needs) {
            return;
        }

        $now = now();
        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Default Organization',
            'slug' => 'default',
            'is_active' => true,
            'created_by_user_id' => null,
            'settings' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Existing non-platform users join the default org; super_admins stay
        // org-less (platform owners).
        DB::table('users')
            ->where('role', '!=', 'super_admin')
            ->whereNull('organization_id')
            ->update(['organization_id' => $orgId]);

        foreach ($this->tables as $table) {
            DB::table($table)->whereNull('organization_id')->update(['organization_id' => $orgId]);
        }
    }

    public function down(): void
    {
        // Data backfill is not reversibly meaningful; leave values in place.
    }
};
