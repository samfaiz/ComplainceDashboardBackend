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

    // Frontend base URL — used to build deep links in admin notification emails.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

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

    // Staged brute-force protection (set a value to 0 to disable that stage):
    //   1. After `lockout_after_attempts` consecutive wrong attempts, lock the
    //      login for `lockout_minutes`.
    //   2. After `disable_after_attempts` consecutive wrong attempts, disable the
    //      account entirely and notify admins/super admins. (Super admins are
    //      locked but never auto-disabled, to avoid locking everyone out.)
    'lockout_after_attempts' => 3,
    'lockout_minutes' => 1,
    'disable_after_attempts' => 6,

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

    /*
    | Auto-populate sample sites/sources/snapshots for newly created users so their
    | dashboard is alive out of the box. Set DEMO_SEED_NEW_USERS=false to disable
    | (e.g. once you're fully in production with real connectors).
    */
    'demo_seed_new_users' => env('DEMO_SEED_NEW_USERS', true),

];
