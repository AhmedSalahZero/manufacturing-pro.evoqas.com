# Excel Model

**What it is.** On the Studies page, the **Excel Model** button downloads the study as a live `.xlsx`: one sheet per page of the
application (Study, Products, Raw materials, Share, Manpower Plan, Expenses, Fixed assets, Opening balances, Costing, Income
statement, Taxes, Cash flow, Balance sheet, Dashboard, Calendar) plus a Cover. Every result is a formula, so changing a yellow
input cell recalculates everything.

**Rules of the workbook**
- Sheet text follows the language of the account (English, or Arabic with right-to-left sheets).
- Sheets are protected **without a password**; only the yellow input cells are open.
- The number of products, rows and months is fixed in the file; every value stays editable.
- The Cover is open, carries ZAVERO and a box for the client's logo.

**Where the code is** — `app/Excel/*` (own .xlsx writer, one class per sheet in `Sheets/`), `ModelDataBuilder` (study → plain data),
`ExcelModelController`, route `projects.excel`. `php artisan zavero:model-dump "<study name>"` writes the same data plus the saved
statements to `storage/app/model-dump-<id>.json` for checking.

**Known limits**
- Months beyond the monthly grid (December of max(end year + 1, start year + 6)) are not reproduced.
- A loan schedule holds at most 240 periods.
- A fixed asset whose customised payment policy ends in zero-rate keys can show a longer drawdown window in the app than in the workbook.
- Dashboard tables are yearly, even for a one-year study.
- No charts yet.

**Tests** — `tests/Unit/Excel/ExcelModelTest.php` (labels, sheets, formulas, protection, names) and `tests/Feature/ExcelModelTest.php`
(download, access, language). Numbers were verified against the application's engines on four fixtures (`tests/Fixtures/excel`), and against the saved statements and dashboard of the two demo studies (Delta Fresh Foods, Nile Valley Foods): every income-statement, cash-flow, balance-sheet, dashboard and ratio line matched within 0.01 (NPV/IRR within 0.005).
