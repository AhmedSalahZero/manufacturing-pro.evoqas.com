# Stage 7 — Dashboard, what-if, public links, printable report

## Screens (all linked from the study form, under "Financial statements")
| Screen | Address | Who |
|---|---|---|
| Dashboard | /projects/{id}/dashboard | anyone with access (notes: editors) |
| What if …? | /projects/{id}/sensitivity | view: anyone; save: editors |
| Printable report | /projects/{id}/report | anyone with access |
| Public links | /projects/{id}/links | owner or company administrator |
| Shared study | /shared/{code} (opens the study form; then /shared/{code}/projects/{id}/...) | nobody signs in; looking only |

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
Random 40-character code, name, on/off switch, delete. A visitor (no sign-in) walks through **every page of the study, start to end, looking only**:
study form (with the tax, return and growth rates), every product, raw materials, manpower plan, expenses, fixed assets, opening balances (existing company),
financial statements, dashboard (with the notes) and what-if. The pages are the same pages and controllers as inside the app, opened through
`/shared/{code}/projects/{id}/...` (GET only, so nothing can be saved). Not reachable from a link: the report, the links page, other studies, the company's people.
The study bar of a visitor has no report and no links; the owner's name is not sent. Visits are logged once per address per hour.
A switched-off link shows a "closed" page (410) on every page; unknown code 404; a code used with another study's id 404. Links of a deleted study stop working.
How it works: the middleware `public-link` (App\Http\Middleware\PublicLink) checks the code and lets the policy `ProjectPolicy::view` accept a guest for that one study;
the screens swap their addresses with resources/js/Utils/publicRoutes.js.

### Fill links (the "Share template" button of the list of studies)
A link has a **type**: `view` (the whole study, looking only, see above) or `fill` (`/fill/{code}`): the customer opens it without signing in and **fills in the
input pages** (study form, products, raw materials, manpower, expenses, fixed assets and, for an existing company, opening balances), saved **straight into the
study**. He never sees statements, dashboard, what-if, report or links (those addresses do not exist under /fill). The three rates (tax, return, growth) are shown
but locked: the server keeps the saved values whatever he sends. After a save the customer is kept inside his pages (next input page; the thank-you page
`/fill/{code}/thanks` when the next place is not one of his pages: after the fixed assets of a new company, after the opening balances, "save and close").
To keep a clean version, make a copy of the study (the Copy button) before sending the link and work in the copy.
Both kinds of link can be **paused / resumed** (switch) and have an optional **expiry date** (the last day the link works): a paused or expired link shows a
"closed" / "expired" page (410), logs nothing and refuses every save. A fill link also stops when the company's subscription has ended.
Where: the Actions column of the list of studies (Share template window: create, copy, pause, expiry date, delete) and the study's Links page (all links).
Table `sharing_links` has two more columns: `type` (view | fill) and `expires_at` (date, empty = never).

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
