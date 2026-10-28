<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — SetLocale
//  Location: app/Http/Middleware/SetLocale.php
//
//  Chooses the language (en / ar) for this request, so every screen,
//  message and email comes out in the right language and direction:
//
//    1. the signed-in account's saved language;
//    2. otherwise what a guest picked on the sign-in page (session);
//    3. otherwise the application's default language (APP_LOCALE).
// ══════════════════════════════════════════════════════════════════

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user) {
            return $user->preferredLanguage();
        }

        $session = $request->hasSession() ? $request->session()->get('locale') : null;
        if (in_array($session, config('zavero.locales'), true)) {
            return $session;
        }

        return in_array(config('app.locale'), config('zavero.locales'), true) ? config('app.locale') : 'en';
    }
}
