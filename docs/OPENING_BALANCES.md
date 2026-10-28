# Opening balances (Stage 5)

Screen: **Projects → a study → Opening balances** (`/projects/{id}/opening-balances`). Only for **existing companies**; a new company starts from zero and has no such page (it also clears these tables when the study is saved as a new company).

## What is entered
| Section | Fields | Monthly figures (pop-up) |
|---|---|---|
| Fixed assets already owned (many) | name, gross, accumulated depreciation, net (calculated), monthly depreciation, months left, administration % (manufacturing = 100 − admin), how it is shared between products | – |
| Cash, customers, inventory (one) | cash and banks, customer receivables, inventory (read-only: finished goods + raw materials typed on the product pages) | collections of the receivables |
| Other debtors (many) | name, amount | settlements |
| Suppliers (one) | amount | payments |
| Other creditors (many) | name, amount | payments |
| Taxes payable (one) | VAT, credit withholding tax, corporate tax | – |
| Long-term loans (many) | balance | instalments, interest |
| Other long-term liabilities (many) | amount | payments |
| Equity (one) | paid-up capital, legal reserve, retained earnings (can be a loss) | – |

The top of the page shows total assets, total liabilities + equity and their difference while you type.

## Rules (ported from the old application)
1. **Statements.** For every balance that is settled over time the monthly statement is: beginning balance − settlement = end balance (the study's months 0 … last month). The quarterly / semi-annual / annual views book the settlements at the last month of the calendar period; the balance still moves month by month. Checked against the old code on 54,000 values (`tests/Fixtures/stage5_golden.json`).
2. **Owned fixed assets.** Monthly depreciation is charged for *months left* from the operation start; split by the administration / manufacturing %. The net value each month = gross − (accumulated at start + depreciation so far). The manufacturing part is shared between the products (by sales, or typed %) and rolled up into each product's overheads and depreciation.
3. **Equity.** Paid-up capital and legal reserve are repeated for every operation month up to the study's end.
4. **Balance check.** Assets (net fixed assets + cash + receivables + inventory + other debtors) must equal liabilities + equity (suppliers + other creditors + VAT + withholding + corporate tax + loans + other long-term + paid-up capital + reserve + retained earnings).

## Differences from the old application (for your decision)
- **Corporate tax payable** is now part of the balance check. The old check forgot it, although the old balance sheet starts from it.
- **Settlements are checked**: they must be inside the study and add up to no more than the balance. The old form accepted anything and left a silent remainder.
- Depreciation of an owned asset starts at the **operation start** (the old code started it late if the operation start was after the study start; for existing companies they are the same).
- The old fixed-asset statement for quarterly/annual views summed running totals (wrong, and never used); only the monthly statement is kept.
- **Nothing is saved until the opening balances balance** (decision of the owner, replacing the old application, which saved first and warned afterwards). If total assets differ from liabilities plus equity, the save is undone and the message shows the difference; the typed figures stay on the screen. The coloured tiles at the top show the difference live while typing.
- The monthly depreciation column was too small (up to 999,999.99); it now holds the same size as other amounts (needs `php artisan migrate`).

## Where the code is
- `app/Calculations/OpeningBalanceCalculator.php` — the engine. `app/Services/OpeningBalanceService.php` — saving, recalculating, totals.
- `app/Http/Controllers/OpeningBalanceController.php`, `app/Http/Requests/UpdateOpeningBalancesRequest.php`, `lang/*/opening.php`.
- `resources/js/Pages/OpeningBalances/Edit.vue`.
- Tests: `tests/Unit/Calculations/OpeningBalancesTest.php`, `tests/Feature/OpeningBalancesTest.php`, `tests/js/openingBalances.spec.js`.
- Used by the financial statements in Stage 6 (customer collections, supplier payments, VAT / withholding / corporate tax starting balances, loan instalments and interest, equity, net fixed assets).
