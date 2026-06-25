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

        User::updateOrCreate(
            ['email' => 'superadmin@compliance.local'],
            [
                'name' => 'Super Admin',
                'role' => User::ROLE_SUPER_ADMIN,
                'password' => Hash::make('Super@12345!'),
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        // Demo connector + 14 days of snapshot data for EVERY seeded user, so each
        // account's dashboard is alive out of the box before a real API is connected.
        $generator = app(\App\Services\Demo\DemoDataGenerator::class);
        User::all()->each(fn (User $u) => $generator->generateFor($u));
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
