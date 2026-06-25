<?php

return [

    /*
    | Cross-Origin Resource Sharing (CORS) configuration for the Next.js SPA.
    | Credentials must be allowed so the Sanctum session + XSRF cookies flow.
    */

    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Cache the CORS preflight (OPTIONS) so cross-subdomain API calls don't
    // re-preflight on every request. Browsers cap this (Chrome ~2h, Firefox 24h).
    'max_age' => 86400,

    'supports_credentials' => true,

];
