# ZAVERO Manufacturing Pro - How it is built

## The pieces

| Layer | Technology | Where |
|---|---|---|
| Server | Laravel 12 on PHP 8.4 | `app/`, `routes/`, `config/` |
| Database | MySQL 8 (utf8mb4: Arabic safe) | `database/migrations/` |
| Screens | Vue 3 + Inertia 2 (pages without a separate API) | `resources/js/Pages/` |
| Styling | Tailwind CSS 4 + the approved design system | `resources/css/` |
| Offline | service worker (Workbox) | `resources/js/pwa/` |
| Builder | Vite 7 | `vite.config.js` |
| Tests | PHPUnit (server) + Vitest (screens) | `tests/` |

Fonts (IBM Plex Sans, IBM Plex Sans Arabic) are bundled with the app (installed from npm): no call to Google, so
they also load offline.

## One kind of account, one sign-in door

- Accounts live in `users` (name, e-mail, password, language, theme, is_active, last_login_at).
- There is no public registration. An administrator creates accounts (the first one comes from `php artisan db:seed`).
- A suspended account (`is_active = false`) cannot sign in and is signed out on its next click (`EnsureActiveUser`).
- Sign-in: 5 wrong tries per e-mail and address lock it for 15 minutes; "no such e-mail" and "wrong password" show the
  same message, so nobody can find out which e-mails have accounts.
- Password rule (one place: `AppServiceProvider`): at least 10 characters, upper and lower case, a number.

## Language and theme

- English and Arabic. The language is kept on the account (so it follows the person to every device) and in the session
  (so the sign-in page remembers a guest's choice). `SetLocale` applies it on every request.
- `<html lang dir data-theme>` is written by the server (`resources/views/app.blade.php`), so Arabic is right-to-left and
  the theme is right from the first paint, with no flash.
- Every word on a screen is in `resources/js/lang/en.js` and `ar.js`; server messages are in `lang/en` and `lang/ar`.
  Tests make sure both languages always have the same words.
- Numbers always use Western digits (0-9) with thousands separators, in both languages (`resources/js/Utils/format.js`).

## Design system

`resources/css/app.css` loads Tailwind, the fonts and the 15 design files in `resources/css/design/`. The design was
checked against the approved HTML, element by element. Each main section of the application has its own colour
(`data-accent` on `<body>`: blue, violet, green, orange, cyan). Tailwind utilities follow the theme too
(`bg-card`, `text-ink`, `border-line` ...).

## Security

- `SecurityHeaders`: Content-Security-Policy (starts in report-only mode, see `.env` SECURITY_CSP), no framing, no
  content-type guessing, strict referrer, HSTS on https.
- `NoStoreForAuthenticated`: a signed-in page is never stored by a browser, proxy or CDN.
- `PreventDuplicateSubmission` (alias `no-duplicate`): the same form sent twice within 8 seconds is recorded once.
- The offline helper never stores signed-in pages (see below).

## Offline

`resources/js/pwa/sw.js` keeps the app's files, the logo/icons and an offline page. Pages always come from the server;
only when there is no connection does the person see the offline page. **Working on plans without any connection is a
later step**: it needs each person's own plans to be kept in a private database on the device and sent to the server
when the connection returns.
