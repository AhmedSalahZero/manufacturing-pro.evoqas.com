<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Ziggy (the route() helper the Vue screens use)
//  Location: config/ziggy.php
//
//  Vue screens write route('login') instead of "/login". These addresses
//  are not given to the screens (they are opened by the browser itself):
//    pwa.*          service worker and manifest
//    csp.report     the browser's security-report address
//    password.reset the reset link (it comes by email)
// ══════════════════════════════════════════════════════════════════

return [
    'except' => ['pwa.*', 'csp.report', 'password.reset'],
];
