# Fixed assets and loans (Stage 4)

Screen: **Projects → a study → Fixed assets** (`/projects/{id}/fixed-assets`).

## What each asset has
- Name, count, price, total, first and last month of purchase (months, saved as a position in the study).
- Depreciation: 0 (none) or 3–25 years, the administration share (the manufacturing share is 100 − administration), a replacement cost % and how often (every 1–5 years).
- Payment terms: **cash** (paid as executed), **customised** (pop-up: days after, % and whether the % is of the total price or of each month's purchase) or **instalments** (pop-up: reservation, contractual payment after N months, grace, number and interval of instalments).
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
- Tests: `tests/Unit/Calculations/FixedAssetsAndLoansTest.php` (+ `tests/Fixtures/stage4_golden.json`), `tests/Feature/FixedAssetsTest.php`, `tests/js/fixedAssets.spec.js`.
- VAT and withholding on purchases arrive with the financial statements (Stage 6).
