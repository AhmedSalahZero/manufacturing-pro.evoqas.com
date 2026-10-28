<?php

use App\Http\Middleware\BlockWritesWhenReadOnly;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureCompanyAdmin;
use App\Http\Middleware\EnsureCompanyMember;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoStoreForAuthenticated;
use App\Http\Middleware\PreventDuplicateSubmission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Application bootstrap
//  Location: bootstrap/app.php
//
//  Routing, middleware and error handling for the whole app.
//
//  On EVERY web request, in this order:
//    SecurityHeaders         → browser protections (CSP, no framing, no sniffing)
//    SetLocale               → en / ar for this person
//    HandleInertiaRequests   → shared props for every Vue screen
//    NoStoreForAuthenticated → signed-in pages are never stored by a browser or proxy
//
//  Route aliases (used in routes/*.php):
//    active        → signs out an account that was deactivated while signed in
//    no-duplicate  → ignores the same form sent twice within a few seconds
//    read-only     → a company whose subscription ended can look but not change
//    super         → the super admin only
//    company       → people of a company (the super admin is sent to the companies)
//    company-admin → the administrator of a company
// ══════════════════════════════════════════════════════════════════

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SecurityHeaders::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            NoStoreForAuthenticated::class,
        ]);

        // Behind a load balancer or hosting proxy every request seems to come from the proxy's
        // address. Set TRUSTED_PROXIES in .env to the proxy address(es), comma-separated, or '*'.
        // Unset = trust none (the safe default).
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        $middleware->alias([
            'active'       => EnsureActiveUser::class,
            'no-duplicate' => PreventDuplicateSubmission::class,
            'read-only'    => BlockWritesWhenReadOnly::class,
            'super'        => EnsureSuperAdmin::class,
            'company'      => EnsureCompanyMember::class,
            'company-admin' => EnsureCompanyAdmin::class,
        ]);

        // Signing out must always work, even when the page was open so long that its
        // security token expired ("419 Page Expired"). A forged sign-out can only sign someone out.
        $middleware->validateCsrfTokens(except: ['logout', 'csp-report']);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // A throttled sign-in must be VISIBLE. A bare 429 page is not an Inertia response, so the
        // form would silently do nothing. Send it back as a normal form error instead.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->header('X-Inertia')) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return back()->withErrors([
                'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        });
    })
    ->create();
