<?php

namespace App\Console\Commands;

use App\Models\ApiSource;
use App\Services\Ingest\IngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshDueSources extends Command
{
    protected $signature = 'sources:refresh-due';

    protected $description = 'Refresh every enabled source whose refresh interval has elapsed (saved-secret sources only).';

    public function handle(IngestService $ingest): int
    {
        // Heartbeat for the system-health check, written every run.
        Cache::put('scheduler.last_run', now(), now()->addHours(1));

        $due = ApiSource::query()
            ->where('is_enabled', true)
            ->where('secret_mode', ApiSource::SECRET_SAVED)
            ->where(function ($q) {
                $q->whereNull('last_run_at')
                    ->orWhereRaw('last_run_at <= DATE_SUB(NOW(), INTERVAL refresh_interval_minutes MINUTE)');
            })
            ->get();

        if ($due->isEmpty()) {
            $this->info('No sources due for refresh.');

            return self::SUCCESS;
        }

        foreach ($due as $source) {
            $secret = $source->revealSecret();

            if ($secret === null) {
                $this->warn("Skipping #{$source->id} ({$source->name}): no stored secret.");
                continue;
            }

            $run = $ingest->run($source, $secret, 'scheduled');
            $this->line("Source #{$source->id} ({$source->name}): {$run->status}, {$run->records_ingested} records.");
        }

        return self::SUCCESS;
    }
}
