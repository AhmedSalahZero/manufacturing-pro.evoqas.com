<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — BlockWritesWhenReadOnly   (route alias: 'read-only')
//  Location: app/Http/Middleware/BlockWritesWhenReadOnly.php
//
//  When a company's subscription day has passed the company becomes READ-ONLY:
//  everyone can still sign in and look at their studies, but nothing can be created,
//  changed or deleted until the super admin renews the subscription.
//  Allowed even then: looking (GET), signing out, and the person's own language / theme.
//  The super admin is never blocked (he renews subscriptions).
// ══════════════════════════════════════════════════════════════════

class BlockWritesWhenReadOnly
{
    private const ALWAYS_ALLOWED = ['logout', 'preferences.update'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $user->isSuperAdmin() || ! $user->company?->isReadOnly()) {
            return $next($request);
        }

        return back()->with('error', __('errors.read_only'));
    }
}
