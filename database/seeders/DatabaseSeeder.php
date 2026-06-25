<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DefaultDashboard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@compliance.local'],
            [
                'name' => 'Security Admin',
                'role' => User::ROLE_ADMIN,
                'password' => Hash::make('Admin@12345!'),
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'analyst@compliance.local'],
            [
                'name' => 'SOC Analyst',
                'role' => User::ROLE_ANALYST,
                'password' => Hash::make('Analyst@12345!'),
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'viewer@compliance.local'],
            [
                'name' => 'Read-Only Viewer',
                'role' => User::ROLE_VIEWER,
                'password' => Hash::make('Viewer@12345!'),
                'is_active' => true,
            ]
        );

        // Demo connector + 14 days of snapshot data so the dashboard is alive
        // out of the box, even before a real EDR/XDR API is connected.
        $this->call(DemoSourceSeeder::class);
        $this->call(NotificationTemplateSeeder::class);

        if (! $admin->dashboards()->where('is_default', true)->exists()) {
            $admin->dashboards()->create([
                'name' => 'Default Dashboard',
                'is_default' => true,
                'api_source_id' => $admin->apiSources()->value('id'),
                'layout' => DefaultDashboard::layout(),
            ]);
        }
    }
}
