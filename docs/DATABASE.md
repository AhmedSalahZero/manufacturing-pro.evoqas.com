# ZAVERO Manufacturing Pro - the database

27 business tables + the system tables from Step B (`users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `failed_jobs`).
They are in `database/migrations/` (files `2026_10_27_0000NN_...`). Run them with `php artisan migrate`.

## Where they come from
They were rebuilt from the real structure of the previous ZAVERO database: same table names, same column names, same order.
That keeps the later import of old data and the comparison of results with the old numbers simple.
The old 86 patch-style migrations were NOT copied.

## What is different from the old database (on purpose)
| Change | Why |
|---|---|
| Every `project_id` is a real link to `projects` (deleting a project removes what belongs to it) | The old database had only 6 links; 30 expenses and 3 fixed assets pointed at projects that no longer existed |
| `projects.user_id` is a real link to `users`, and a person who owns projects cannot be deleted | No lost projects |
| `projects.business_sector_id`, `sensitivities.user_id/project_id`, `sharing_links.user_id/project_id` are now the same size as the id they point to (were `int`) | Needed for real links |
| `sensitivities`: the 10 `float(10,2)` columns are now `decimal(14,2)` | The old size could not hold a value above 99,999,999.99 |
| `sharing_links.link_code` is unique, `sharing_links_visitors.link_code` is indexed | A link code must identify one link; lookups are fast |
| `seasonality`: index on (`model_name`, `model_id`); the two database triggers are NOT recreated | The monthly split moves into PHP, where it can be tested |
| `failed_jobs` and the old `password_resets` are not repeated | Step B already has `failed_jobs` and `password_reset_tokens` |

Everything else - 444 columns - is identical to the old database (type, size, null-ability, default).

## Kept for now, decide later
- `projects.product_first ... product_fifth` (+ selling dates): old leftovers; real products are rows in `products`. Kept because the old code still reads them.
- Calculated series stored as JSON next to the inputs (for example `products.product_overheads_statement`): kept. They will be recalculated by the new calculation engine, and the tests will compare them with the old saved results (tolerance 0.01).

## Test
`tests/Feature/DatabaseStructureTest.php` checks that all 27 tables exist with exactly these columns, that deleting a project removes what belongs to it, and that a person who owns projects cannot be deleted.
