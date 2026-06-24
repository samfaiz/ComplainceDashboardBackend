<?php

use Illuminate\Support\Facades\Schedule;

// Check every minute which sources are due for a refresh based on their
// individual interval (1h / 2h / ...). Also writes a heartbeat used by the
// system-health panel. Run `php artisan schedule:work` to activate.
Schedule::command('sources:refresh-due')
    ->everyMinute()
    ->withoutOverlapping();
