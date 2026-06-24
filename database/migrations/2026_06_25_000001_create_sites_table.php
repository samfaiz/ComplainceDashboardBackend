<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['user_id']);
        });

        Schema::table('api_sources', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('api_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });

        Schema::dropIfExists('sites');
    }
};
