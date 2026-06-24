<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_source_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('running'); // running | success | failed
            $table->string('trigger')->default('scheduled'); // scheduled | manual | login
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('records_ingested')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['api_source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_runs');
    }
};
