<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;

class PurgeExpiredDemos extends Command
{
    protected $signature = 'demos:purge';

    protected $description = 'Delete demo organizations whose 1-hour window has elapsed (and all their data).';

    public function handle(): int
    {
        // Small grace so a request near expiry isn't yanked mid-flight.
        $cutoff = now()->subMinutes(5);

        $orgs = Organization::where('is_demo', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $cutoff)
            ->get();

        foreach ($orgs as $org) {
            // Members first — their FK is nullOnDelete (cascades their sources/data);
            // the org delete then cascades remaining org-stamped rows.
            $org->users()->delete();
            $org->delete();
        }

        $this->info("Purged {$orgs->count()} expired demo organization(s).");

        return self::SUCCESS;
    }
}
