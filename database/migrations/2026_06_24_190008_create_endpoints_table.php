<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_source_id')->constrained()->cascadeOnDelete();

            // Normalized fields shared across all EDR/XDR/SIEM vendors.
            $table->string('external_id')->nullable();
            $table->string('hostname')->nullable();
            $table->string('os_platform')->nullable();   // Windows | macOS | Linux ...
            $table->string('os_version')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('health_status')->nullable(); // online | offline | error | ...
            $table->timestamp('last_seen_at')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('mac_address', 64)->nullable();
            $table->boolean('is_isolated')->nullable();
            $table->string('compliance_status')->nullable(); // compliant | non_compliant | unknown

            $table->json('extra')->nullable(); // additional mapped custom fields
            $table->json('raw')->nullable();   // full original record from the vendor

            $table->timestamp('captured_at')->nullable();

            $table->index(['snapshot_id']);
            $table->index(['api_source_id', 'hostname']);
            $table->index(['api_source_id', 'os_platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoints');
    }
};
