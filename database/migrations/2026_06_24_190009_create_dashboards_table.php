<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->default('My Dashboard');
            $table->boolean('is_default')->default(false);

            // Widget layout: [{ id, type, title, x, y, w, h, config }]
            $table->json('layout')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboards');
    }
};
