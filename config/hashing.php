<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | This is a security dashboard, so we default to Argon2id — the winner of
    | the Password Hashing Competition and the current OWASP-recommended
    | algorithm. The native PHP PASSWORD_ARGON2ID implementation is used.
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),  // 64 MB
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    | Automatically rehash the user's password during login if the work factor
    | configured above has changed, keeping stored hashes up to date.
    */

    'rehash_on_login' => true,

];
