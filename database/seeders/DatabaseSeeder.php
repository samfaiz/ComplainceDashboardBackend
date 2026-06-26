<?php

namespace Database\Seeders;

use App\Actions\ProvisionOrganization;
use App\Models\User;
use App\Services\Demo\DemoDataGenerator;
use App\Support\DefaultDashboard;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Platform owner — manages every organization, belongs to none.
        User::updateOrCreate(
            ['email' => 'superadmin@compliance.local'],
            [
                'organization_id' => null,
                'name' => 'Platform Owner',
                'role' => User::ROLE_SUPER_ADMIN,
                'password' => Hash::make('Super@12345!'),
                'is_active' => true,
            ]
        );

        // Demo organization + its first admin (also seeds the org's notification
        // templates and an empty mail settings row).
        $org = app(ProvisionOrganization::class)->create('Demo Organization', [
            'name' => 'Security Admin',
            'email' => 'admin@compliance.local',
            'password' => 'Admin@12345!',
            'must_change_password' => false,
        ]);

        app(Tenancy::class)->runFor($org->id, function () use ($org) {
            $analyst = User::updateOrCreate(
                ['email' => 'analyst@compliance.local'],
                [
                    'organization_id' => $org->id,
                    'name' => 'SOC Analyst',
                    'role' => User::ROLE_ANALYST,
                    'password' => Hash::make('Analyst@12345!'),
                    'is_active' => true,
                ]
            );

            $viewer = User::updateOrCreate(
                ['email' => 'viewer@compliance.local'],
                [
                    'organization_id' => $org->id,
                    'name' => 'Read-Only Viewer',
                    'role' => User::ROLE_VIEWER,
                    'password' => Hash::make('Viewer@12345!'),
                    'is_active' => true,
                ]
            );

            $admin = User::where('email', 'admin@compliance.local')->first();

            // Demo connector + 14 days of snapshots for each org member, so their
            // dashboards are alive out of the box. Org is auto-stamped via context.
            $generator = app(DemoDataGenerator::class);
            foreach (array_filter([$admin, $analyst, $viewer]) as $member) {
                $generator->generateFor($member);
            }

            if ($admin && ! $admin->dashboards()->where('is_default', true)->exists()) {
                $admin->dashboards()->create([
                    'name' => 'Default Dashboard',
                    'is_default' => true,
                    'api_source_id' => $admin->apiSources()->value('id'),
                    'layout' => DefaultDashboard::layout(),
                ]);
            }
        });
    }
}
