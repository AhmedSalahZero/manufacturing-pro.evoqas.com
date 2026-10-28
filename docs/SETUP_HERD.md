# Setup on Herd - from the zip to a running ZAVERO Manufacturing Pro

You do this once. Every step says what to do and what you should see. If something is different, stop and send me the
text on the screen (a screenshot is fine).

**This project is NEW and separate.** It is not the old Zavero and it does not use the old database.

## 0. What you need
- **Herd** (you have it) with **PHP 8.4**.
- **MySQL** running (your own MySQL, or Herd's).
- **Node.js 22** (Herd can provide it; otherwise install "Node 22 LTS" from nodejs.org).

## 1. Put the project in the right place
1. Unzip `Step-B-Project-Skeleton.zip`. You get one folder: `zavero-manufacturing-pro`.
2. Move that folder into your **Herd sites folder** (the folder Herd shows under *Settings > Paths*).
3. Check: inside it you must see `app`, `public`, `resources`, `composer.json`.
4. In Herd, open the site list: `zavero-manufacturing-pro` appears. Choose **PHP 8.4** for it.

## 2. Create the empty database (once)
In your MySQL tool run:

    CREATE DATABASE zavero_manufacturing_pro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

## 3. Open a terminal inside the project folder
Right-click the folder `zavero-manufacturing-pro` and open a terminal there (or open Herd's terminal and run
`cd` to the folder). All commands below are typed there, one at a time.

## 4. Install and prepare

| # | Type this | What you should see |
|---|---|---|
| 1 | `composer install` | Many lines, takes a few minutes, ends without red errors |
| 2 | Windows: `copy .env.example .env`   Mac: `cp .env.example .env` | Nothing (the file `.env` now exists) |
| 3 | `php artisan key:generate` | "Application key set successfully." |
| 4 | Open `.env` and check the lines `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` match your MySQL | (change only if your MySQL differs; Herd/root with no password is the default) |
| 5 | `php artisan migrate` | A list of tables with "DONE" |
| 6 | `php artisan db:seed` | "Administrator created" and, **once**, a password. **Write it down.** |
| 7 | `npm install` | Finishes without red errors |
| 8 | `npm run build` | Ends with "precache ... entries" |

(If you prefer your own password: before step 6 write it in `.env` after `ADMIN_PASSWORD=` - at least 10 characters,
upper and lower case and a number.)

## 5. Open it
Go to **http://zavero-manufacturing-pro.test** - you should see the sign-in page with the factory picture.
Sign in with `admin@zavero.test` and the password from step 6. Switch **EN / ع** and the moon button in the top bar.

## 6. Make it installable as an app (optional, recommended)
A browser only allows "install" and the offline helper on a secure address. In the terminal type:

    herd secure

then open **https://zavero-manufacturing-pro.test**. In Chrome or Edge an install icon appears in the address bar.

## 7. Run the tests - send me the result

    php artisan test
    npm test

Expected: all green. `php artisan test` has 82 tests, `npm test` has 26.
**Please send me the last 20 lines of both**, green or red.

## If something goes wrong
| You see | Do |
|---|---|
| `composer` or `php` "not found" | Open the terminal from Herd, or restart the computer once after installing Herd |
| "could not find driver" / "Connection refused" during `migrate` | MySQL is not running, or `.env` DB lines are wrong |
| A white page or "Vite manifest not found" | You skipped `npm run build` (step 8) |
| Everything looks unstyled | Run `npm run build` again |
| You forgot the administrator password | In the terminal: `php artisan tinker` then `User::where('email','admin@zavero.test')->first()->update(['password'=>'NewPassw0rd-2026']);` |
