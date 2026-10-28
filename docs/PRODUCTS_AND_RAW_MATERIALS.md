# Stage 2 — Products and raw materials

## What the screens do
* **Study → Next steps** lists every saved product and the raw-material payment page.
* **Product form** (`Products/Edit`): sales by year (capacity, target %, local %, growth, price), monthly pattern (flat / quarterly / monthly, must total 100 %), opening finished goods (existing companies only), collection terms (cash % + up to two later payments, must total 100 %, first-year and later-years policy), recipe (raw-material % per year), comment, yearly results.
* **Raw-material payments** (`RawMaterials/Payments`): payment terms per material, same rules.
* Every save recalculates monthly sales, finished-goods inventory, raw-material consumption, purchases, supplier payments and customer collections.

## Calculation rules (ported from the old application)
* Yearly quantity: target = capacity x target %; local = target x local %; export = target x (100 - local %). Prices: next = round(previous x (1 + growth %)).
* Monthly quantity = year quantity x seasonality share (normalised over the months from the selling start).
* Finished goods: need = this month + next coverage months; made = max(0, need - opening); closing = opening + made - sold.
* Raw material consumed = recipe % x the year's average selling price per unit x units made. Purchases use the same inventory routine.
* Collections/payments: due days under 30 = same month, otherwise round(days / 30) months later; VAT and withholding follow the same terms; export has no VAT or withholding; money falling after the last month is dropped.
* One extra year (a copy of the last) is stored, as the old system did.

## Deliberate differences from the old application (for discussion)
1. Quarterly/annual statements now sum VAT and withholding over the whole period (old: last month only).
2. Equal due days are added together (old: the second overwrote the first).
3. Raw-material consumption is priced on the year's average selling price per unit, so units made in a month with production but no sales are now costed (old: consumption was zero in such a month, because it was derived from that month's sales value; this was an old-application error).
4. Recipe totals above 100 % only show a warning.
5. Old stored collection statements and consumption in the test data were stale, so they were not used as truth; monthly sales, quantities and finished-goods inventory match the old results.
6. A new company starts with 0 finished goods and 0 raw-material opening inventory.
7. Changing the study start or selling start shifts the month-indexed series.
