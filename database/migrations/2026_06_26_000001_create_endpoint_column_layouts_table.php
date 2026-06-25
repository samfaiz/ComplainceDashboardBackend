<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_column_layouts', function (Blueprint $table) {
            $table->id();
            // null user_id = the shared org/owner default; otherwise a personal override.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('columns');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_column_layouts');
    }
};
