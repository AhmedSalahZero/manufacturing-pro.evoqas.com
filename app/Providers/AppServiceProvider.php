<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — AppServiceProvider
//  Location: app/Providers/AppServiceProvider.php
//
//  App-wide wiring, in one place:
//    · Frontend    — Vite pre-loads the next screens.
//    · Safety while building — lazy loading and silently dropped attributes
//      throw on your computer, so slow queries (N+1) and typos show up early
//      instead of on the live server.
//    · HTTPS is forced on the live server.
//    · One password rule for the whole application.
// ══════════════════════════════════════════════════════════════════

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // At least 10 characters with upper and lower case letters and a number.
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());
    }
}
