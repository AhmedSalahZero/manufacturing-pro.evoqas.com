# Financial statements (Stage 6)

Screen: **Projects → a study → Financial statements** (`/projects/{id}/statements`). Read-only: nothing is typed here, everything is calculated from the other screens. Three tabs (Income statement, Cash flow, Balance sheet), one column per year; click a year to open its months. Four coloured tiles on top (total sales, total net profit, net margin, cash at the end) and a green / red line that says whether the balance sheet balances in every month.

## When it is calculated
Every time anything in the study is saved, `SalesCalculationService::recalculate` runs the chain; the last step is `StatementsService`:
1. **Cost of goods sold** of every product (`FgCostingCalculator`).
2. **VAT and corporate tax** (`TaxCalculator`).
3. **Income statement, cash flow, balance sheet** (`FinancialStatementsCalculator`).
4. The results are saved in `income_statements`, `cash_in_out_statements`, `balance_sheets` and `projects.vat_statements` / `corporate_taxes_statement` (for the dashboards of Stage 7). The screen builds the same numbers again from the saved inputs.

## Cost of goods sold
Everything spent on making a product in a month (raw materials, direct labour, manufacturing overheads) is added to what is already in stock. Each kind of cost is shared over the units available (weighted average): units **sold** take their part (cost of goods sold), units **in stock** keep theirs. Depreciation of the machines is followed the same way but is not added again (the overheads already contain it); the income statement shows it as a separate line to give EBITDA.

## The three statements
- **Income statement:** revenue − cost of goods sold = gross profit; − operating expenses (general, sales, marketing, and start-up / pre-operating for a new company; staff of those types; administration depreciation) = EBIT; EBITDA = EBIT + depreciation; − finance cost (loan interest after the asset is in use); = profit before tax; − corporate tax = net profit. Retained earnings carry the profit of the months before.
- **Cash flow:** collections from customers + owners' equity + bank loans drawn; minus payments to suppliers, salaries and expenses, taxes, fixed assets, loan instalments. If a month would end below zero the owners put in the missing amount ("top-up"), so cash is never negative.
- **Balance sheet:** assets (cash, customers, stock, other debtors, VAT receivable, assets under construction, net fixed assets) = liabilities (suppliers, asset suppliers, VAT payable, withholding tax, salary tax and insurance, corporate tax, other creditors, loans, other long-term) + equity (paid-up capital, additional paid-in capital = equity put in + top-ups, legal reserve, retained earnings, profit of the period). The "Check" line must be 0 in every month; the page warns if it is not.

## Egyptian tax rules
| Tax | Rule |
|---|---|
| **VAT** | Charged on sales minus VAT paid on raw material purchases. The balance is paid in the **following month**. A month where purchase VAT is higher is a **credit**: not paid, not refunded, carried forward against the next months and shown under **current assets as "VAT receivable"** (the liabilities side shows "VAT payable" only when VAT is owed). The VAT owed on day one (existing company) is paid in the second month. |
| **Debit withholding** (customers deduct it from what they pay us) | Builds up through the year and is **deducted from the corporate tax** of that year. |
| **Corporate tax** | Worked out on the profit of each **calendar year** (profit and loss months net against each other; a loss year pays nothing), booked in the **last month of the year** (the last study month for a part year). What is still due after deducting the withheld tax is paid in **April of the next year**. If more was withheld than the tax, nothing is paid and the difference stays as a credit. Tax owed on day one is paid in the first April (study starting January–April) or with the first year's tax. |
| **Credit withholding** (we deduct it from suppliers) | Paid **every quarter** (January, April, July, October) — worked out with the raw material payments. The amount owed on day one is paid in the first month. |

## Differences from the old application (for your decision)
1. **Tax basis.** Old: tax month by month, losing months ignored. New: tax on the whole year's profit (a loss month reduces the profit of the year). Set in `StatementsService::TAX_BASIS`.
2. **Loss carry-forward is not modelled** (a loss year does not reduce the next year's tax). Egyptian law allows five years: to be decided.
3. **Part year.** The tax of the last (part) year is booked in the study's last month. The old code dropped the last calendar bucket.
4. **Opening corporate tax** is paid in April (old: May for a January start).
5. **VAT credit** is carried forward (old: a quirk could "refund" it as a negative payment).
6. **Supplier payments** on the cash flow now include the raw material payments (the old summary left them out of that line, although the total included them).
7. **Interest during construction** is added to the cost of the asset and is not expensed a second time. The old code did both, which made the balance sheet unbalanced when the repayment started before the asset was finished.
8. **Paid-up capital and legal reserve** are shown for every month of the study (the old arrays missed the months before the operation start).
9. Raw materials still use the year's average price when consumed (unchanged from Stage 2).

## Checks
- `tests/Unit/Calculations/FinancialStatementsTest.php` — worked by hand, plus `tests/Fixtures/stage6_golden.json`: cost of goods sold of 6 products, 3 VAT statements, 3 corporate tax statements and the income statement, cash flow and balance check of 2 whole studies from the old application (within 0.01).
- `tests/Feature/StatementsTest.php` — the screen, access, the balance sheet balancing for a new company (staff, expenses, machine with loan) and for an existing company with opening balances, VAT, tax timing, saved statements.
- `tests/js/statements.spec.js` — the screen and the names (English and Arabic) of every row.

## Where the code is
`app/Calculations/FgCostingCalculator.php`, `TaxCalculator.php`, `FinancialStatementsCalculator.php` · `app/Services/StatementsService.php` · `app/Http/Controllers/StatementsController.php` · `resources/js/Pages/Statements/Show.vue` · words in `resources/js/lang/en.js` / `ar.js` (`statements`).


## Decisions (owner)
- VAT credit: shown as **VAT receivable** under current assets (no word "credit" on screen).
- Corporate tax: losses are **not** carried forward (conservative); every year's tax stands on its own.

## Fix: payable balance of a one-time expense paid later (found with the demo studies)
The payable statement of an expense (`CollectionPolicy::buildStatement`) only had a line for the months where the expense itself
existed. A one-time expense paid "50 % after 30 days, 50 % after 60 days" has an expense in ONE month only, so its balance was
dropped the month after, while the cash was still paid in the two following months: the balance sheet was out by the amount
paid in the first payment month. The statement now covers every month from the first expense to the last expense or payment
(`IntervalSums::periodEnds`). Sales and purchase statements are unchanged (they already run to the end of the study).
