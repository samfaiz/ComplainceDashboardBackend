<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Address that platform-level notifications (e.g. new account requests)
        // are sent to. Lives on the platform mail row (organization_id = NULL).
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->string('notify_email')->nullable()->after('reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropColumn('notify_email');
        });
    }
};
