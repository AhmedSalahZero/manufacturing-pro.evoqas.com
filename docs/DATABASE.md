# ZAVERO Manufacturing Pro - the database

31 business tables (27 original + 4 for sales channels and export, see below) + the system tables from Step B (`users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `failed_jobs`).
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
| `sensitivities.fx_rate_change` `decimal(14,2)` default 0 added (Stage B: the FX what-if, in %) | The what-if now also moves the exchange rates |
| `sharing_links.link_code` is unique, `sharing_links_visitors.link_code` is indexed | A link code must identify one link; lookups are fast |
| `seasonality`: index on (`model_name`, `model_id`); the two database triggers are NOT recreated | The monthly split moves into PHP, where it can be tested |
| `failed_jobs` and the old `password_resets` are not repeated | Step B already has `failed_jobs` and `password_reset_tokens` |

Everything else - 444 columns - is identical to the old database (type, size, null-ability, default).

## Kept for now, decide later
- `projects.product_first ... product_fifth` (+ selling dates): old leftovers; real products are rows in `products`. Kept because the old code still reads them.
- Calculated series stored as JSON next to the inputs (for example `products.product_overheads_statement`): kept. They will be recalculated by the new calculation engine, and the tests will compare them with the old saved results (tolerance 0.01).

## Test
`tests/Feature/DatabaseStructureTest.php` checks that all 31 tables exist with exactly these columns, that deleting a project removes what belongs to it, and that a person who owns projects cannot be deleted.


## Product sales rebuild - Stage A (study settings)
Migration `2026_11_11_000001_add_export_and_sales_channels.php`. Full description: `docs/SALES_CHANNELS_AND_EXPORT.md`.

| Table / column | What it holds |
|---|---|
| `projects.has_export_sales` | The study toggle "This study has export sales" (default No) |
| `fx_rates` | One row per foreign currency of a study: `currency`, `rates` (year index => units of the study currency per 1 foreign unit). Unique per study + currency |
| `sales_channels` | Local customer types of a study (Retail, Wholesale ...): `name`, `position`. Unique name per study |
| `export_markets` | Countries a study exports to: `name`, `currency`, `position`. Unique name per study |
| `product_segments` | How a product uses a channel (`kind` = local) or a market (`kind` = export): share per year, price difference / first foreign price and growth, optional own collection terms, saved monthly results. Filled from Stage C; the table exists now so the study copy and the "in use" check already know it |
| `seasonality.scope` | `local` or `export`: a product can have two patterns. Existing rows are `local` |
