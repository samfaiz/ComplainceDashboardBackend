<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_key')->index();
            $table->string('channel')->default('email');
            $table->string('recipient'); // email address sent to
            $table->string('subject');
            $table->string('status'); // queued | sent | failed | skipped
            $table->text('error')->nullable();
            $table->json('payload')->nullable(); // event payload for replay/debug
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
