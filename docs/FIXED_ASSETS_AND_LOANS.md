# Fixed assets and loans (Stage 4)

Screen: **Projects → a study → Fixed assets** (`/projects/{id}/fixed-assets`).

## Four kinds of asset
| Kind | Dates | Payments | Depreciation |
|---|---|---|---|
| **Land** | one purchase month | cash, customised or instalments | none |
| **Construction** | a start and an end month; spent **flat** (equal parts) or on an **S-curve** | cash, customised (each line chooses its own *taken from*) or instalments | yes |
| **Machine** | purchase, delivery and *ready* (installed and tested) months | cash, customised, instalments or **milestones** (a % at purchase, at delivery and when ready, each with optional days after) | yes, from after it is ready |
| **Other** | one purchase month | cash, customised or instalments | yes |

**On the screen** the page has one card for each kind (Land, Construction, Machines and equipment, Other fixed assets), each with its own "add" button, and an asset sits in the card of its kind (to change the kind, remove the asset and add it in the other card). Each card shows only what makes sense for its kind:
- Land: *Purchase date*; no depreciation line.
- Other: *Purchase date* and the depreciation line.
- Construction: *Start date*, *End date* and *Spending over the months* (equal each month or S-curve). In the payment pop-up each line has *Taken from*; the first line of a new construction is ready as a down payment on the total price (same month).
- Machines: *Purchase date*, *Delivery date* and *Date to be ready*, and the payment option *By milestones* (down payment at purchase date / at delivery / after installation, each with optional days after; must total 100 %). The customised pop-up has no *Taken from* (days count from the purchase date).
- A later start moves the delivery and ready dates (machine) or the end date (construction) with it, so the dates always stay in order.

- A construction's *taken from* is chosen **per payment line**: for example 25 % of the **total** as a down payment, then 50 % of **each month's work** after 30 days and 25 % after 60 days.
- A machine's cost counts as spent when it is paid (an advance to the supplier) or, for what is still unpaid, when it is delivered, so the supplier balance is never negative. While it is delivered but not yet ready it stays under construction and collects loan interest; it is transferred the month after it is ready.
- The S-curve is a half-cosine: slow start, fastest in the middle, slow end (for 12 months: 1.7, 5.0, 7.9, 10.4, 12.1, 12.9, 12.9, 12.1, 10.4, 7.9, 5.0, 1.7 %).
- The Excel Model and the study report show the kinds too: the workbook has a *Kind of asset* and a *Spending* list for every asset, the delivery date, the milestone lines and the S-curve as live formulas (checked against the engine on ten assets covering every kind, to 0.01); the report lists each asset's kind, its dates, the *taken from* of every customised payment line and the milestones.

## What each asset has
- Kind, name, count, price, total, first and last month of purchase (months, saved as a position in the study; a machine also has a delivery month and its last month is the month it is ready).
- Depreciation: 0 (none) or 3–25 years, the administration share (the manufacturing share is 100 − administration), a replacement cost % and how often (every 1–5 years).
- Payment terms: **cash** (paid as executed; a machine is paid at the purchase), **customised** (pop-up: days after, % and whether the % is of the total price or of each month's purchase) **instalments** (pop-up: reservation, contractual payment after N months, grace, number and interval of instalments) or, for a machine, **milestones**.
- Funding: own money % and a bank loan for the rest (pop-up: interest, grace months, tenor, instalment interval).
- How the manufacturing depreciation is shared between products: by revenue, or typed % (must total 100).

## Rules (ported from the old application and checked against its saved results, tolerance 0.01)
1. Purchase cost is spread equally from the first to the last purchase month.
2. Own money pays first; the loan pays the rest. Drawdown interest = balance × rate / 360 × days since the last draw, and is added to the balance.
3. The loan starts at the last drawdown with the balance (plus capitalised interest) as principal; interest only during the grace period, then equal instalments.
4. Interest incurred before operation is capitalised into the asset; the asset is transferred and starts to depreciate after it is ready or when operation starts, whichever is later.
5. Replacement cost is added to the depreciable base and depreciated; replacement months follow the interval until the study ends.
6. Manufacturing depreciation goes to the products (and into their overheads); administration depreciation goes to the administration expenses.

## Differences from the old application (for your decision)
- **Payable** is now the cumulative purchases minus payments for every month (the old figure stopped at the last payment and ignored purchases before the first payment).
- A customised payment with no basis chosen paid nothing in the old code; the basis is now always chosen.
- The old screen did not check the months, grace vs tenor, or 100% totals and crashed. These are now validated with clear messages.
- Product overheads now include expenses, indirect manpower **and** depreciation, as in the old roll-up.

## Where the code is
- `app/Calculations/FixedAssetCalculator.php`, `LoanCalculator.php` — the engine.
- `app/Services/FixedAssetService.php` — saving and recalculating. `app/Http/Controllers/FixedAssetController.php`, `app/Http/Requests/UpdateFixedAssetsRequest.php`.
- `resources/js/Pages/FixedAssets/Edit.vue` — the screen.
- Columns added for the kinds: `asset_type`, `execution_curve`, `delivery_date`, `milestone_payments` (migration `2026_11_10_000001_add_asset_type_to_fixed_assets_table.php`).
- Tests: `tests/Unit/Calculations/FixedAssetsAndLoansTest.php` (+ `tests/Fixtures/stage4_golden.json`), `tests/Feature/FixedAssetsTest.php`, `tests/js/fixedAssets.spec.js`.
- VAT and withholding on purchases arrive with the financial statements (Stage 6).
