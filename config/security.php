<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connector secret encryption
    |--------------------------------------------------------------------------
    |
    | API keys / tokens for the EDR/XDR/SIEM sources are encrypted with
    | AES-256-GCM (authenticated encryption) using this dedicated 256-bit key,
    | which is kept separate from APP_KEY so the two concerns can be rotated
    | independently. See App\Services\Crypto\SecretBox.
    |
    */

    'data_encryption_key' => env('DATA_ENCRYPTION_KEY'),

    'cipher' => 'aes-256-gcm',

    /*
    |--------------------------------------------------------------------------
    | Login / session security
    |--------------------------------------------------------------------------
    */

    // Flag a user's session in red when they log in from a never-before-seen IP.
    'flag_new_ip' => true,

    // How many minutes of inactivity before a user is considered "offline".
    'online_window_minutes' => 5,

    // Lock the account after this many consecutive failed logins (0 = disabled).
    'max_login_attempts' => 8,
    'lockout_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Refresh intervals offered in the setup wizard (in minutes)
    |--------------------------------------------------------------------------
    */

    'refresh_intervals' => [15, 30, 60, 120, 180, 360, 720, 1440],

    /*
    | How many recent snapshots keep their full per-endpoint detail. Older
    | snapshots retain only their aggregated summary (used for trend charts).
    */
    'endpoint_retention_snapshots' => 30,

];
