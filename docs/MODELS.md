# ZAVERO Manufacturing Pro - the models

One model (a PHP class that reads and writes one table) for each table, in `app/Models/`. `User` (Step B) now also knows the person's projects.

| Model | Table | Belongs to / has |
|---|---|---|
| `Project` | projects | belongs to a person and a business sector; has products, raw materials, manpower, expenses, fixed assets, the 8 opening balances, allocations, seasonality, loan schedules, sharing links; one what-if (sensitivity), one income statement, balance sheet and cash-in/out statement. Deleted projects are kept (soft delete) and can be restored |
| `Product` | products | belongs to a project; many raw materials (with a percentage); allocations; one monthly sales pattern (`seasonality`) |
| `RawMaterial` | raw_materials | belongs to a project; many products |
| `Manpower`, `Expense`, `FixedAsset` and the opening balances | one table each | belong to a project (`FixedAsset` also has its loan schedules) |
| `IncomeStatement`, `BalanceSheet`, `CashInOutStatement` | saved results | belong to a project |
| `Seasonality` | seasonality | the monthly sales pattern of a product |
| `SharingLink`, `SharingLinkVisitor` | sharing_links, sharing_links_visitors | who shared a project, and who opened the link |
| `BusinessSector` | business_sectors | has projects |

## Rules built in
- Columns that hold lists or monthly series (JSON) come back as PHP arrays; dates as dates; yes/no as true/false; money and rates as exact decimals (text such as `"22.50"`, never rounded floats).
- Every column may be filled except the id, so screens must only pass validated data in (never the whole request).
- These models hold no calculations. The calculation engine comes in a later step.
- Test: `tests/Feature/ModelsTest.php`.
