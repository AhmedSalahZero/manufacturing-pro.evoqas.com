# Manpower plan and expenses (Stage 3)

Two screens, both reached from the study form ("Next steps") and in order after the raw material payment terms:

| Screen | Address | What it does |
|---|---|---|
| Manpower plan | `/projects/{id}/manpower` | Salary settings, six staff types, hirings per month, sharing of direct-labour and overheads cost between products |
| Expenses | `/projects/{id}/expenses` | Four kinds of expense, categories, months, payment terms, sharing between products |

Every save recalculates the whole study (`SalesCalculationService::recalculate`, which now ends with `CostCalculationService::recalculate`).

## Staff rules (kept from the old application)

* The months are the **operation months**, from the operation start to December of the extra year after the study.
* Headcount of a month = headcount of the month before (people today in the first month) + people hired that month.
* The **net salary** grows by the yearly increase every 12 operation months (not on the calendar year).
* Salaries paid = net salary x headcount.
* Employer cost = net salary x headcount / (1 - (income tax % + social insurance %)).
* Income tax + insurance = employer cost x (tax % + insurance %), paid one month later.
* A new company starts with 0 people today.
* **Direct labour** and **manufacturing overheads** staff cost is given to the products, either by their sales each month, or by typed percentages (adding up to 100) shared out each month only between the products that have started selling. Overheads staff are also listed as "indirect manpower" of each product.

## Expense rules

* Kinds: fixed monthly (grows by a yearly %), one time (spread over N months), % of the sales of chosen products, cost per unit sold of chosen products.
* Categories: manufacturing, other operation, marketing, sales, general. A **new company** also has pre-operating and start-up expenses. Manufacturing and other operation expenses are shared between the products.
* An expense whose end month is the last month of the study carries on through the extra year.
* Payment terms: cash (same month); quarterly / semi-annually / annually (the amounts of the block are paid together in the **first** month of the block, as before); customised (up to 5 lines of "paid after N days, X %", adding to 100; days under 30 land in the same month, otherwise round(days / 30) months; equal days are added together).

## Months are positions

Months typed on the screens are saved as positions in the study (0 = the study's first month): `expenses.start_date`, `expenses.end_date`, the keys of `manpowers.hirings`. Moving the study start moves them all.

## Where things are

* Calculation (no database): `app/Calculations/ManpowerCalculator.php`, `ProductAllocation.php`, `ExpenseCalculator.php`
* Putting results in the database: `app/Services/CostCalculationService.php`
* Saving the forms: `app/Services/CostPlanService.php`, `app/Http/Requests/Update{Manpower,Expenses}Request.php`
* Screens: `resources/js/Pages/Manpower/Edit.vue`, `resources/js/Pages/Expenses/Edit.vue`
* Tests: `tests/Unit/Calculations/StaffAndExpensesTest.php` (hand-worked + old saved results in `tests/Fixtures/stage3_golden.json`), `tests/Feature/StaffAndExpensesTest.php`, `tests/js/costScreens.spec.js`

## Checked against the old test data

Staff: 3,677 monthly figures matched. Expenses: 659 matched. The rows that did not match were old rows saved by an earlier version of the old code (salary increase compounded monthly; "cash" paid one month late; % expenses based on out-of-date product sales) and are not carried over.
