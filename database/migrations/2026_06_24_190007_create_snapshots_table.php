<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('captured_at');
            $table->unsignedInteger('endpoint_count')->default(0);

            // Pre-aggregated rollups powering the trend graphs without scanning endpoints.
            $table->json('summary')->nullable(); // by_os, by_status, by_agent_version, compliance, online/stale/offline...

            $table->timestamps();

            $table->index(['api_source_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
