<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_active');
            $table->timestamp('expires_at')->nullable()->after('is_demo');
            $table->index(['is_demo', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['is_demo', 'expires_at']);
            $table->dropColumn(['is_demo', 'expires_at']);
        });
    }
};
