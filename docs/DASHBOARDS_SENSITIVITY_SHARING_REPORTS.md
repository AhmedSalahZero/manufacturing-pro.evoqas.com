# Stage 7 — Dashboard, what-if, public links, printable report

## Screens (all linked from the study form, under "Financial statements")
| Screen | Address | Who |
|---|---|---|
| Dashboard | /projects/{id}/dashboard | anyone with access (notes: editors) |
| What if …? | /projects/{id}/sensitivity | view: anyone; save: editors |
| Printable report | /projects/{id}/report | anyone with access |
| Public links | /projects/{id}/links | owner or company administrator |
| Shared page | /shared/{code} | nobody signs in; read-only |

## Dashboard (ported from `Project::getDashboardViewVars`)
Free cash flow = EBIT + depreciation + change in working capital − tax on EBIT (only if EBIT > 0) − capex.
WACC per year = equity % × return rate + debt % × cost of debt. Terminal value (last year) = FCF × (1+g) ÷ (WACC − g).
Each year is discounted at (1 + its WACC)^n, n = 1, 2, 3… (as the old app). IRR = rate making the yearly flows (with terminal value) sum to 0.
Payback: monthly collections − cash out, continued 10 years (last 12 months repeated, growing by g); first month the running total reaches the equity put in.
Ratios: current / quick / cash ratio and working capital at year end; DSO / DIO / DPO / CCC from average monthly balances over a 360-day year.
Verified: 10 old studies, FCF / WACC / NPV / IRR within 0.01 (`tests/Fixtures/stage7_golden.json`, `DashboardTest`).

Changes from the old app: terminal value is left out (with a warning) if WACC ≤ growth; cost of debt weights each asset by counts × amount × (100 − equity %) (the old formula used 100 − equity%/100).
Yearly figures use calendar years of the study, as the statements do.

## What if …?
Per product (first five, in order): sales target ±%, raw-material cost ±%, collection delay in days. Saved in `sensitivities`.
The scenario re-runs the whole calculation chain inside a database transaction that is always rolled back, so the study never changes and the same engines are used.
Shown side by side: total revenue, net profit, NPV, IRR, payback and three charts.

## Public links
Random 40-character code, name, on/off switch, delete. Visitors see dashboard + statements + the two notes only. Visits are logged once per address per hour.
A switched-off link shows a "closed" page (410); unknown code 404. Links of a deleted study stop working.

## Report
One page: summary, every dashboard section, notes, yearly statements. Button "Print / Save as PDF" (browser print; approved print layout, A4 landscape).

## Valuation tab - result of the valuation
Under the discounted cash flow table: a row "Discounted free cash flow" (the cash flow with terminal value divided by the
discount factor, per year) and four tiles: enterprise value (the sum of that row = the NPV), IRR, payback period and the share
of the terminal value in the enterprise value (shown only when the enterprise value is positive). The old application showed
NPV, IRR and payback as rows under the same table.

## Printable report — the whole study (fix12)

The report (`/projects/{id}/report`) now holds every building block of the study, in this order, each part starting on a new printed page:
study summary and contents list; products (sales by year, monthly pattern, finished goods inventory, collection terms, raw material recipe, yearly results);
raw materials (VAT, withholding, coverage, payment terms); manpower plan (salary settings, positions with hirings, cost sharing, cost per year);
expenses (with payment terms and cost per year); fixed assets (price, depreciation, payment terms, instalments, funding and loan, sharing, results per year);
opening balances (existing companies only); financial statements; results (KPIs, charts, valuation, notes).
Empty parts are left out. The tables are built by `App\Services\ReportInputsService` and drawn by `ReportBlock.vue`; they are read-only.
