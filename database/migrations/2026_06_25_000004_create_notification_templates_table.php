<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per supported event_key (login.new_ip, dashboard.assigned, etc).
        // Admin can edit subject / body / enabled; defaults seeded on install.
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('display_name');
            $table->string('category')->index(); // security | account | dashboard | source | vulnerability
            $table->string('default_severity')->default('info'); // info | warning | critical
            $table->string('subject');
            $table->mediumText('body_html');
            $table->mediumText('body_text')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
