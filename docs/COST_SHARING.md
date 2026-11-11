# How costs are shared between products (and what happens in a month with no activity)

Applies to every cost that is shared between products: direct labour and manufacturing-overheads staff, manufacturing and other-operation
expenses (fixed monthly and one-time), depreciation of machines bought in the study, and depreciation of machines owned on the first day.
Expenses "as % of sales" and "per unit" are not shared: they belong to the product(s) that produce them.

**Active product.** A product is active in a month when it makes or sells something in it (quantity made, quantity sold or sales value above 0).

| Case | What happens |
|---|---|
| Shared by sales, month has sales | each product takes its share of the month's sales value (as before) |
| Shared by sales, month has production but no sales | the products that make something share equally |
| Shared by typed %, some products active | only the active products share it, their typed % re-spread to 100 (Pro-A carries everything until Pro-B starts making or selling) |
| No product active (no sales and no production) | nothing is shared; the cost stays visible and is shown in **General and administrative expenses** for that month only |
| Activity starts again | the cost returns to the products |

Costs given to a product go to its finished-goods stock (cost of goods sold when the units are sold). Costs that no product took are
expensed in the month, so the balance sheet always balances. Machine depreciation no product took is also added back in EBITDA, like office depreciation.

Where it lives
* Engine: `ProductAllocation::activity()`, `activeTypedShares()`, `allocate()` (app/Calculations/ProductAllocation.php), used by CostCalculationService, FixedAssetService and OpeningBalanceService.
* What no product took is saved on the study: `projects.unallocated_costs` (direct_labor, manufacturing_overheads, expenses), `unallocated_depreciation`, `unallocated_opening_depreciation` (month => amount). StatementsService adds them to general expenses.
* Excel model: Share sheet ("Products making or selling" rows) and the "not shared" rows on Manpower Plan, Expenses, Fixed assets and Opening balances; the Income Statement general expenses include them.
* Excel IRR (Dashboard) is tried from five starting guesses and ignores a result at or below -99%.
