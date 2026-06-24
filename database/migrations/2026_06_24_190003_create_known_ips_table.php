<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('known_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->boolean('trusted')->default(true);   // admin can untrust / a new IP starts flagged
            $table->unsignedInteger('login_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_ips');
    }
};
