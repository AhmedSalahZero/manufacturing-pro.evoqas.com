# Step B - Foundation

What Step B delivers, how each part works, where its code and its test are, and how to try it yourself.

## 1. Look and language
- The approved design, carried over exactly: `resources/css/design/*.css` (checked against the original HTML).
- English and Arabic, switched with **EN / ع** in the top bar (and on the sign-in page). Arabic is right-to-left from the
  first paint. The choice is saved on the person's account, so it follows them to every device.
- Light and dark theme (moon / sun button), also saved on the account.
- Numbers always use 0-9 (`resources/js/Utils/format.js`).
- Every screen word is in `resources/js/lang/en.js` and `ar.js`; server messages in `lang/en`, `lang/ar`.
- Tests: `PreferencesTest`, `TranslationsTest`, `tests/js/i18n.spec.js`, `tests/js/preferences.spec.js`, `tests/js/format.spec.js`,
  `DesignSystemCssTest`.

## 2. Signing in
| What | How | Code |
|---|---|---|
| Sign in | E-mail + password at `/login` | `app/Http/Requests/Auth/LoginRequest.php` |
| Sign out | Button in the top bar | `AuthenticatedSessionController` |
| Forgot password | Link on the sign-in page, e-mail with a link valid 60 minutes | `PasswordResetLinkController`, `NewPasswordController` |
| First administrator | `php artisan db:seed` | `database/seeders/AdminUserSeeder.php` |

- No public registration.
- 5 wrong tries lock sign-in for 15 minutes. A suspended account cannot sign in and is signed out at once.
- Password rule: 10+ characters, upper and lower case, a number.
- Tests: `SignInTest`, `PasswordResetTest`, `AdminSeederTest`, `RoutesAndWiringTest`.

## 3. Installable app and offline page
- Installable on phone, tablet and computer (`vite.config.js` manifest, icons in `public/images/icons`).
- With no connection a page shows a bilingual "You are offline" page; a banner says so while working.
- A new version waits until the person taps **Update now**.
- Signed-in pages are never stored by the offline helper.
- Working on plans offline is a later step.
- Tests: `PwaTest`, `SecurityTest`.

## 4. Security
Browser protections, no-store for signed-in pages, double-submit guard. Tests: `SecurityTest`, `DuplicateSubmissionTest`.

## 5. Where the next steps plug in
- A new screen: a Vue file in `resources/js/Pages/`, a route in `routes/web.php` inside the signed-in group, a tab in
  `resources/js/Layouts/navigation.js`, words in `en.js` and `ar.js`. The tests `tests/js/wiring.spec.js` and
  `RoutesAndWiringTest` check that all of them agree.

## Try it yourself
1. Sign in; switch language and theme; reload: the choice stays.
2. Sign out, open `/login`: the page is in the language you last picked.
3. Click "Forgot your password?", type the administrator e-mail; open `storage/logs/laravel.log`: the e-mail with the link is there.
4. Type a wrong password 5 times: sign-in is locked for 15 minutes.
5. Offline page: open the browser developer tools (F12), tab Network, tick "Offline", reload: you see the offline page. (Needs the built app on an https address, see SETUP_HERD step 6.)
