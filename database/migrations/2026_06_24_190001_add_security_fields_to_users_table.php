<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')->after('email'); // admin | analyst | viewer
            $table->boolean('is_active')->default(true)->after('role');

            // MFA (TOTP) — secret & recovery codes stored encrypted at rest.
            $table->boolean('mfa_enabled')->default(false)->after('is_active');
            $table->text('mfa_secret')->nullable()->after('mfa_enabled');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at');

            // Login / IP security tracking.
            $table->timestamp('last_login_at')->nullable()->after('mfa_recovery_codes');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->string('current_ip', 45)->nullable()->after('last_login_ip');
            $table->boolean('ip_flagged')->default(false)->after('current_ip');
            $table->timestamp('last_seen_at')->nullable()->after('ip_flagged');

            // Brute-force protection & forced rotation.
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('last_seen_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->boolean('must_change_password')->default(false)->after('locked_until');

            // Arbitrary per-user UI preferences.
            $table->json('preferences')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role', 'is_active', 'mfa_enabled', 'mfa_secret', 'mfa_confirmed_at',
                'mfa_recovery_codes', 'last_login_at', 'last_login_ip', 'current_ip',
                'ip_flagged', 'last_seen_at', 'failed_login_attempts', 'locked_until',
                'must_change_password', 'preferences',
            ]);
        });
    }
};
