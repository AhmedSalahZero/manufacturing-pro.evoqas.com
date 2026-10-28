# ZAVERO Manufacturing Pro

Financial planning for the manufacturing sector - rebuilt on **Laravel 12 + Vue 3 (Inertia) + Tailwind 4 + MySQL**,
installable as an app (PWA), in **English and Arabic**.

| Folder / file | What it is |
|---|---|
| `docs/SETUP_HERD.md` | **Start here.** Step by step: from the zip to a running application on Herd |
| `docs/STEP_B_FOUNDATION.md` | What this step delivers, where each part lives, how to try it |
| `docs/ARCHITECTURE.md` | How the application is built |
| `app/`, `routes/`, `config/`, `database/` | The server (Laravel) |
| `resources/js/` | The screens (Vue) and the words (`lang/en.js`, `lang/ar.js`) |
| `resources/css/` | The approved design (`design/` has 15 small files) |
| `lang/` | Server messages and e-mails in English and Arabic |
| `public/` | Pictures, icons, the offline page |
| `tests/` | The automated tests |

Run the tests any time:

    php artisan test      (server)
    npm test              (screens)
