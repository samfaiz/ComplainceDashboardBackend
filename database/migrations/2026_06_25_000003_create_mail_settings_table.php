<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Singleton table — one row holds active SMTP/from settings. Secret
        // (password) is encrypted with DATA_ENCRYPTION_KEY via the model accessor.
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('transport')->default('smtp'); // smtp | log
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('encryption')->nullable(); // tls | ssl | null
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
