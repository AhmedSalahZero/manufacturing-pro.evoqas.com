{{-- ═══════════════════════════════════════════════════════════════
     ZAVERO Manufacturing Pro — Root page for every screen
     Location: resources/views/app.blade.php
     Every Inertia (Vue) screen renders inside this page.
     · lang / dir come from the language, so Arabic is right-to-left
       from the very first paint (no flash of left-to-right).
     · data-theme comes from the person's saved theme (or their last choice
       on the sign-in page), so there is no flash of the wrong theme.
     · The fonts are bundled with the app (no Google call), so they also
       work offline.
     · The manifest makes ZAVERO installable (PWA).
     · @routes gives Vue Laravel's route names (Ziggy: route()).
     ═══════════════════════════════════════════════════════════════ --}}
@php
    $locale = app()->getLocale();
    $theme = \App\Http\Middleware\HandleInertiaRequests::themeFor(request());
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ in_array($locale, config('zavero.rtl_locales'), true) ? 'rtl' : 'ltr' }}" data-theme="{{ $theme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="{{ $theme === 'dark' ? '#0B1730' : '#0B2A78' }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <title inertia>{{ config('app.name') }}</title>
        <link rel="icon" type="image/png" href="/images/icons/favicon-64.png">
        <link rel="apple-touch-icon" href="/images/icons/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
