<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — NoStoreForAuthenticated
//  Location: app/Http/Middleware/NoStoreForAuthenticated.php
//
//  Marks every signed-in response as un-storable.
//
//  Every page carries its whole Inertia payload inline in the HTML:
//  the signed-in user and all of that page's figures. Anything that keeps
//  a copy of that HTML and replays it later (a browser, a proxy, a CDN,
//  a hosting page-cache) could show one person's financial plans to
//  somebody else. This happened on an earlier project through an offline
//  helper that cached pages, so ZAVERO's offline helper
//  (resources/js/pwa/sw.js) never stores signed-in pages either.
//
//  Note: the Cache Storage API used by service workers ignores these
//  headers. The headers are the backstop; not caching in the worker is the fix.
//
//  Guest responses (sign-in page) are left alone: identical for everybody.
// ══════════════════════════════════════════════════════════════════

class NoStoreForAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->user()) {
            return $response;
        }

        // no-store is the only directive that actually forbids writing a copy.
        $response->headers->set('Cache-Control', 'no-store, no-cache, private, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
