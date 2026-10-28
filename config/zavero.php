<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Application settings
//  Location: config/zavero.php
//
//  The first administrator account is created by `php artisan db:seed`
//  from these values (set them in .env).
// ══════════════════════════════════════════════════════════════════

return [

    'name' => 'ZAVERO Manufacturing Pro',

    // Languages the application speaks, and which of them is right-to-left.
    'locales' => ['en', 'ar'],
    'rtl_locales' => ['ar'],

    // First administrator (created by the seeder). Leave ADMIN_PASSWORD empty to get a random one printed on screen.
    'admin' => [
        'name'     => env('ADMIN_NAME', 'Administrator'),
        'email'    => env('ADMIN_EMAIL', 'admin@zavero.test'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'support_email' => env('SUPPORT_EMAIL', 'support@zavero.test'),

    // The choices on the study form (Projects). The words shown for each live in resources/js/lang/en.js and ar.js.
    'study' => [
        'min_years'         => 1,
        'max_years'         => 7,
        'currencies'        => ['egp', 'usd', 'euro', 'aed', 'sar', 'qar'],
        'default_currency'  => 'egp',
        // Kept exactly as the previous ZAVERO stored them ('litter' is the old spelling of liter), so old data fits.
        'measurement_units' => ['ton', 'kg', 'gram', 'litter', 'milliliter', 'carton', 'box', 'piece', 'each', 'barrel', 'bottle', 'others'],
        'rm_coverage_days'  => [0, 15, 30, 45, 60, 75, 90, 120, 150, 180],
    ],

];
