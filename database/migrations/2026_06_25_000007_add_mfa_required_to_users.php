<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When true, the user is allowed (and on next login required) to enroll
            // an authenticator. Without this flag, the self-service MFA UI is hidden
            // and the MFA setup endpoints reject the request. Set by an admin.
            $table->boolean('mfa_required')->default(false)->after('mfa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mfa_required');
        });
    }
};
