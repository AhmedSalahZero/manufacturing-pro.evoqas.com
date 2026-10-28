<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — PwaController ( /sw.js and /manifest.webmanifest )
//  Location: app/Http/Controllers/PwaController.php
//
//  Serves the service worker: the small program the browser keeps so the
//  app can open with no signal. `npm run build` writes it to
//  public/build/sw.js; it is served from the site root (/sw.js) so that it
//  may look after the whole site.
//
//  Never cached by the browser (Cache-Control: no-cache): when we publish
//  an update, devices pick up the new worker on their next visit.
//
//  /manifest.webmanifest: the app manifest, also at the root. The worker's
//  file list names it there, and one missing file makes the browser reject
//  the whole worker.
// ══════════════════════════════════════════════════════════════════

class PwaController extends Controller
{
    public function serviceWorker(): BinaryFileResponse
    {
        $path = public_path('build/sw.js');

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type'           => 'application/javascript; charset=utf-8',
            'Cache-Control'          => 'no-cache, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /**
     * The app manifest, also at the site root. The offline helper's file
     * list contains "manifest.webmanifest" read from the root (/sw.js), and
     * if that one address answered "not found", the browser would reject the
     * whole helper — and the app would not open without internet.
     */
    public function manifest(): BinaryFileResponse
    {
        $path = public_path('build/manifest.webmanifest');

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type'  => 'application/manifest+json',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
