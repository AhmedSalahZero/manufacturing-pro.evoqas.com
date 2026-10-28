<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Sign-in settings
//  Location: config/auth.php
//
//  One kind of account (users), one sign-in door (guard "web").
//  Password reset links are valid for 60 minutes.
// ══════════════════════════════════════════════════════════════════

return [

    'defaults' => [
        'guard'     => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_reset_tokens',
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
