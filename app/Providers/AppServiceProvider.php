<?php

namespace App\Providers;

use App\Services\Notifications\MailConfigurator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Pull runtime SMTP config from the mail_settings table.
        $this->app->make(MailConfigurator::class)->apply();
    }
}
