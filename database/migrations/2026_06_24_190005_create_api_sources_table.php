<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('vendor')->default('generic'); // generic|crowdstrike|defender|sentinelone|wazuh

            // Connection
            $table->string('base_url');
            $table->string('auth_type')->default('bearer'); // bearer|api_key_header|basic|oauth2_client_credentials
            $table->json('auth_config')->nullable();         // non-secret auth params (header name, token url, client_id, username, scopes...)

            // Secret handling
            $table->string('secret_mode')->default('saved'); // saved | per_login
            $table->text('secret_encrypted')->nullable();    // AES-256-GCM ciphertext (null when per_login)
            $table->string('secret_hint')->nullable();       // last 4 chars for display only

            // Request shape (how to pull the device/agent list)
            $table->json('request_config')->nullable();      // method, path, query, headers, pagination, data_path
            $table->json('field_mappings')->nullable();      // normalized_field => json path within a record

            // Scheduling
            $table->unsignedInteger('refresh_interval_minutes')->default(60);
            $table->boolean('is_enabled')->default(true);

            // Last run status (denormalized for quick display)
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status')->nullable();        // success | failed | running
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('latest_snapshot_id')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_sources');
    }
};
