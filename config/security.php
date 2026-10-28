<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Browser security headers (audit Q24)
//  Location: config/security.php
//
//  Sent by App\Http\Middleware\SecurityHeaders on every page.
//  Each can be changed in .env without touching code.
//
//  SECURITY_CSP   report-only the browser blocks NOTHING and quietly reports what the policy would
//                             have blocked into storage/logs/csp.log (DEFAULT, the safe way to start)
//                 enforce     the browser BLOCKS anything the policy does not allow. Switch to this
//                             after a few days of normal use with an empty csp.log.
//                 off         no Content-Security-Policy header
//  SECURITY_HSTS  true        on https, tell the browser to use https only for a year
//  SECURITY_HEADERS false     switch ALL of them off (only if a hosting panel already sets them)
// ══════════════════════════════════════════════════════════════════

return [
    'enabled' => (bool) env('SECURITY_HEADERS', true),

    'csp'     => env('SECURITY_CSP', 'report-only'),

    'hsts'    => (bool) env('SECURITY_HSTS', true),

    // One year, in seconds.
    'hsts_max_age' => 31536000,
];
