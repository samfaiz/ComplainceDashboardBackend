<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();              // captured even for unknown users
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('successful')->default(false);
            $table->boolean('is_new_ip')->default(false);     // first time we have seen this IP for the user
            $table->string('failure_reason')->nullable();     // bad_credentials | mfa_failed | locked | inactive
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
