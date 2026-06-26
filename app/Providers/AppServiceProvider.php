<?php

namespace App\Providers;

use App\Support\Tenancy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request/process-scoped tenant context. MUST be a singleton so the
        // middleware, global scope, and services all share one instance.
        $this->app->singleton(Tenancy::class);
    }

    public function boot(): void
    {
        // SMTP config is now per-organization and applied at send time
        // (NotificationService / NotificationController) rather than globally.
    }
}
