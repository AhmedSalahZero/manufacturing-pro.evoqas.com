# Sales channels and export (product sales rebuild)

Reference: `PRODUCT_SALES_SCOPE_OF_WORK.md` (draft 1). This file records what each stage delivered.

## Stage A - Study settings and database

### What the user sees (study page, new card "Sales channels and export")
| Part | Behaviour | Scope |
|---|---|---|
| Sales channels | A list of names (add / remove), up to 8. Names are unique inside the study, capital letters ignored | R1.4, D7 |
| "This study has export sales" Yes / No | Default No. Switching to No while a product still has export quantity asks first; on confirm the server sets every product's export to 0 (local share 100 %) and removes its export allocations | R1.1, R1.6 |
| Foreign currencies | Tick up to 6 currencies. The study currency is never offered. One row of FX rates with a box for every study year | R1.2, R1.3 |
| Export markets | Country + currency (only the ticked currencies), up to 10 | R1.5, D7 |

Only the Yes switch shows currencies and markets. With No, FX rates are not demanded; the saved currencies and markets are kept so switching back to Yes brings them back.

### Checks (server, English and Arabic) - `UpdateProjectRequest`
- Export Yes: at least one foreign currency; a rate above zero for every study year of every chosen currency.
- The study currency cannot be a foreign currency; a market's currency must be one of the chosen currencies.
- Names unique (channels among channels, markets among markets, capital letters ignored); limits 8 / 10 / 6.
- A channel or market that a product uses (a row in `product_segments`) cannot be deleted: the message lists the products (D8).
- Export switched to No while a product has export quantity: refused unless `clear_export` is sent (the screen sends it after the user confirms).
- The four keys (`has_export_sales`, `sales_channels`, `fx_rates`, `export_markets`) are optional: a form that does not send them leaves the saved settings untouched (the demo seeders and old tests keep working).

### Year index
FX rates are stored per study year: index 0 = the calendar year the study starts in, the same index the product sales tables use.
The number of years is `StudyCalendar::yearCount()` (start year to the year of the last study month; March 2026 + 5 years = 2026 to 2031 = 6 years).

### Study copy / delete
`ProjectService::copy` copies the toggle, channels, markets, FX rates, product segments (re-pointed at the copied product, channel and market) and both seasonality patterns.
Deleting a study (or a product) removes its channels, markets, FX rates and segments through the database links.

### Code map
| Piece | File |
|---|---|
| Tables and columns | `database/migrations/2026_11_11_000001_add_export_and_sales_channels.php` |
| Models | `FxRate`, `SalesChannel`, `ExportMarket`, `ProductSegment`; `Project::salesChannels/exportMarkets/fxRates/productSegments`; `Product::segments/exportSeasonality` |
| Checks | `app/Http/Requests/UpdateProjectRequest.php` |
| Saving, copy, "export off" | `app/Services/ProjectService.php` (`syncSales`, `clearExport`, `copy`) |
| Study page data | `ProjectController@edit` (`sales`, `options.maxChannels / maxMarkets / maxCurrencies`) |
| Screen | `resources/js/Components/SalesChannelsCard.vue`, helpers `resources/js/Utils/salesSettings.js` |
| Words | `resources/js/lang/en.js` + `ar.js` (`projects.sales.*`), `lang/en|ar/projects.php` (`sales`, `validation`, `fields`) |
| Limits | `config/zavero.php` (`study.max_channels`, `max_markets`, `max_foreign_currencies`) |

### Tests
`tests/Feature/SalesSettingsTest.php`, `tests/Unit/StudyCalendarYearCountTest.php`, `tests/js/salesCard.spec.js`, `tests/js/salesSettings.spec.js`;
`DatabaseStructureTest` (31 tables, new columns) and `ModelsTest` (new models and relations) were updated.

### Decisions taken in this stage
- No old studies exist (only the demo and Sky Healthy studies, which are updated in Stage D), so there is no data migration and no built-in "Export" market stored in the database. The calculation (Stage B) will treat a study with no channels / markets as one channel "Local" and one market "Export" so the existing "old results" tests stay identical.
- The euro keeps the code `euro`; screens show EUR.
- A name must be unique inside its own list (channels among channels, markets among markets).

---

## Stage B — calculation, saving, validation and the FX what-if

### How a product is calculated now
1. **Group quantity** of each year = capacity x target % split by the local share (unchanged).
2. **Segment quantity** = group quantity x the channel's (market's) allocation % of that year.
3. **Channel price** = base local price x (1 + the channel's price difference %), not rounded to whole numbers.
4. **Foreign price** = the typed first price, then each year the year before x (1 + growth %), rounded to 2 decimals.
   **Export price in the study currency** = foreign price x the FX rate of the same year.
5. **Seasonality**: channels use the local pattern, markets the export pattern (the export pattern defaults to the local one).
6. **Units to make / stock** use the total monthly quantity (local + export).
7. **Average price** = total sales value / total quantity of the year. It feeds the raw material cost (as before, with the new average).
8. **Collections** are worked out per channel / market with its own terms (or the product's terms), VAT and withholding on local only, then added into the local, export and total statements.

A product with no saved channels / markets behaves as one implicit channel "local" and one implicit market "export" (the old figures), so a study that defines no channel or market is calculated exactly as before.

### Rules checked when a product is saved (only once the study defines a channel or market)
| Rule | Message key |
|---|---|
| Export switched off in the study: the export share must be 0 | `products.errors.exportOff` |
| Local quantity needs at least one channel; export quantity at least one market | `noChannel`, `noMarket` |
| Every year with quantity: channel shares add up to 100, market shares add up to 100 | `channelTotal`, `marketTotal` |
| Every market needs an FX rate above zero for every year it exports | `noFx` |
| A channel / market with its own terms: terms add up to 100 | `collectionTotal` (existing) |
| Export pattern adds up to 100 | `seasonalityTotal` (existing) |

A save that does not send `segments` keeps the saved channels and markets.

### Saved on the product after each calculation
`product_segments.monthly_quantities / monthly_values / collection_statement` per channel and market; `products.sales_target_values` = total of all segments per year; `products.export_price_per_unit` = average export price when real markets exist.

### FX what-if
`sensitivities.fx_rate_change` (decimal 14,2, default 0, between -99 and 1000). The comparison scales every saved FX rate by (1 + change/100) on a copy that is rolled back, and the what-if "collection days" also moves the own terms of channels and markets. The field is shown on the what-if screen only when the study has export and FX rates.

### Code map (Stage B)
| Piece | File |
|---|---|
| Pure segment rules | `app/Calculations/SegmentCalculator.php` |
| Engine | `app/Calculations/ProductSalesCalculator.php` (`local_segments`, `export_segments`, `export_seasonality_weights`; returns `segments`, `average_prices`) |
| Prices per year | `app/Services/SegmentPricing.php` |
| Saving | `app/Services/ProductPlanService.php` |
| Recalculation, collections | `app/Services/SalesCalculationService.php` |
| Checks | `app/Http/Requests/UpdateProductRequest.php` (`checkSales`) |
| What-if | `SensitivityService`, `SensitivityController`, `Pages/Sensitivity/Show.vue`, migration `2026_11_11_000002_add_fx_change_to_sensitivities.php` |
| Tests | `tests/Unit/Calculations/ProductSegmentsTest.php`, `tests/Feature/ProductSegmentsTest.php` |

---

## Stage C — the product form

### What the person sees
On a product's page (only when the study has defined at least one channel or market):
1. **Sales card** — as before. With channels and markets, the product-level export price rows are replaced by the markets' prices, and "Target sales value" is the sum of all channels and markets. With export switched off a note reminds that the local share must be 100%.
2. **Sales channels and export markets** (new card, tabs **Local** / **Export**; no tabs when export is off):
   - share of the quantity per channel (market) and year, with a green/red total per year;
   - Local: price difference % per channel and year; Export: first price in the market's currency and yearly growth %, and the exchange rates of the study (read only);
   - "What this gives": quantity, price, value per channel (market) and year, the total and the average price; for markets also the foreign price, the exchange rate and the value in the foreign currency;
   - collection terms: each channel / market follows the product's terms or has its own (a pop-up with the same first-year / later-years terms).
3. **Monthly pattern** — one card for local sales; a second card for export sales when the study has export.
4. **Results** — each year opens to show local and export sales and, under them, every channel and market (value and units).

A study with no channels or markets keeps the old screen exactly (R8).

### How it is wired
| Piece | File |
|---|---|
| Data for the form (study lists, FX, one row per channel / market with what is saved or a starting point, export pattern, results by channel / market) | `app/Http/Controllers/ProductController.php` (`salesSettings`, `segmentRows`, `segmentResults`, `pattern`) |
| The panel | `resources/js/Components/ProductSegments.vue` |
| The arithmetic shown while typing (mirrors `SegmentCalculator`) | `resources/js/Utils/segments.js` |
| The page | `resources/js/Pages/Products/Edit.vue` |
| Words | `resources/js/lang/en.js` + `ar.js` (`products.segments.*`, `products.season.titleLocal / hintLocal / titleExport / hintExport`) |
| Tests | `tests/js/segments.spec.js`, `tests/js/productSegmentsScreen.spec.js`, `tests/Feature/ProductScreenSegmentsTest.php` (helpers: `tests/Concerns/BuildsSalesStudy.php`) |

### Rules of the screen
- Every channel and market of the study is a row. A new product starts with 100% on the first channel and the first market; the other rows start at 0.
- A year's price difference / growth that was never typed starts from the year before.
- The export pattern starts as a copy of the local one (until you change it).
- The euro is stored as `euro` and shown as EUR.

## Stage D — Excel, report, dashboard, demo data

**Excel model.** A study with foreign currencies gets a new sheet **Exchange rates** (one yellow row per currency, the last year repeats the year before). Each product sheet with channels or markets shows, per channel: share %, price difference %, units and price; per market: first foreign price, yearly growth %, foreign price (first price, then the year before × (1 + growth), 2 decimals), price in the study currency (foreign price × the rate of the year) and units. The average price per unit (it feeds the raw materials) is total value ÷ total units. Local sales follow the local seasonality, export sales their own pattern. A channel or market with its own terms gets two policy lines and its own collection statement; the others follow the product terms, and the product's "Collections - total" adds everything up. A product with channels only (or markets only) treats the other side as before: one implicit channel / market at the product's own price. Studies without channels and markets produce exactly the same workbook as before.
Code: `app/Excel/Sheets/FxSheet.php`, `SalesSheet.php` (`segmentsYearly`), labels in `LabelsData.php`.
Verified: a test study (`tests/Fixtures/excel/fixture6.php`: two channels, one with own terms, two markets in USD and EUR, a second product with markets only) was recalculated with LibreOffice and compared with the engine: all 756 monthly figures (units, values, units to make, VAT, withholding, cash received, receivable) matched, no error cells.

**Report.** Per product: channels (share, price difference), markets (share, foreign price, price in the study currency), export seasonality, own collection terms added to the collection table, and the results by channel and market per year. The exchange-rate forecast is shown once, at the start of the products section. `app/Services/ReportInputsService.php`.

**Dashboard.** The Overview tab gets a "Sales by channel and market" block (local by channel, export by market) when the study has them. `DashboardService::salesBreakdown`, `DashboardView.vue`.

**Demo data.** Both demo studies and Sky Healthy now use channels, markets and exchange rates (Sky Healthy: Gulf and Middle East in USD, Europe in EUR). `DemoSeeder.php`, `SkyHealthySeeder.php`.

| Tests | `tests/Unit/Excel/ExcelModelTest.php` (fixture 6), `tests/Feature/DemoSeederTest.php`, `tests/Feature/SkyHealthySeederTest.php`, `tests/js/dashboard.spec.js` |
|---|---|
