<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Demo\DemoDataGenerator;
use Illuminate\Console\Command;

class PopulateDemoData extends Command
{
    protected $signature = 'demo:populate
        {email? : Attach demo data to this user (defaults to the first super admin / admin)}
        {--all : Generate demo data for every user}
        {--days=14 : Days of snapshot history to generate}';

    protected $description = 'Create sample sites, sources and snapshot history so a user\'s dashboard looks populated before real connectors are added.';

    public function handle(DemoDataGenerator $generator): int
    {
        $days = max(1, (int) $this->option('days'));

        if ($this->option('all')) {
            $users = User::all();
            $this->withProgressBar($users, fn (User $u) => $generator->generateFor($u, $days));
            $this->newLine(2);
            $this->info("✓ Demo data generated for all {$users->count()} users.");

            return self::SUCCESS;
        }

        $email = $this->argument('email');

        $user = $email
            ? User::where('email', $email)->first()
            : User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])
                ->orderByRaw("FIELD(role, 'super_admin', 'admin')")
                ->first();

        if (! $user) {
            $this->error($email ? "No user found with email {$email}." : 'No super-admin/admin user found to attach demo data to.');

            return self::FAILURE;
        }

        $this->info("Generating {$days} days of demo data for {$user->email}…");

        $r = $generator->generateFor($user, $days);

        $this->info("✓ Done — {$r['sites']} sites, {$r['sources']} sources, ~{$r['per_snapshot']} endpoints per snapshot.");
        $this->line('Log in as that user and open the Dashboard to preview. Delete the "Demo EDR — …" sources from API Sources once you connect real ones.');

        return self::SUCCESS;
    }
}
