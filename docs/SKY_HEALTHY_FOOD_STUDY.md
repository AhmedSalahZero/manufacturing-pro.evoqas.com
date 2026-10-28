# Sky Healthy Food - SKY-CAL eggshell calcium carbonate plant (initial feasibility study)

A seven-year study for a new plant inside Sky Healthy Food, which already runs a pasteurized-egg factory (so it already has
the main raw material: eggshell, about 93-95% calcium carbonate). Made with the same forms, checks and calculations as any
study typed in by hand.

## Run it

    composer dump-autoload
    php artisan db:seed --class=SkyHealthySeeder

Safe to run again: it only adds the study if it is not there yet. Tests: `php artisan test --filter=SkyHealthySeederTest`.

**Company and login.** The seeder creates the company **Sky Healthy Food** and its administrator (only if missing):
`admin@sky.com` / `Sky@Zavero2026`. The study belongs to that company. The demo company is never used or changed. No public link is made.

**Excel.** Open the study, then use **Excel Model** on the Studies page: the application writes the full seven-year workbook.

## The study in short

| | |
|---|---|
| Company | New business inside Sky; EGP; sector Food & Beverage |
| Period | January 2027 - December 2033 (7 years) |
| Installation | January - June 2027 (ordering, delivery, installation, trial batches) |
| Operation and first sales | July 2027 (membrane powder October 2027) |
| Products | SKY-CAL Bio-Active (600 tons a year), SKY-CAL Industrial/Feed (900), Eggshell Membrane Powder (48) |
| Capacity | One shared line of about 125 tons of powder a month; the same capacity in every year (the ramp-up is in "share of capacity sold") |
| Share sold | Bio-Active 25 / 50 / 70 / 80 / 85 / 85 / 85 %, Industrial 35 / 60 / 75 / 85 / 90 / 90 / 90 %, Membrane 15 / 30 / 45 / 55 / 60 / 60 / 60 %. In 2027 the first figure is multiplied by months selling / 12 (6, 6 and 3 months) |
| Second production line | None. Shell supply and 85-90% use do not call for one |
| Staff | 13 positions, 27 people from July 2027, 35 from July 2028; salaries = first estimate + 25%; +12% a year |
| Assets | 6 assets, EGP 65.6 million in total (about 10% contingency inside), 22% bank loans on the building fit-out and the two main lines |
| Yearly renewal capex | 0.5% building, 2% washing line, 3% micronization line, 2% packaging / dust / effluent, 10% vehicles, 8% laboratory (replacement every 1 year) |
| Return required on equity | 25%; tax 22.5%; perpetual growth 3% |

## Where the numbers come from

| Type | What |
|---|---|
| Client documents | Process flow (Dr. Mahmoud Emara, June 2026): 100-150 tons a month, 12 stages, membrane 3-5 tons a month, water 12-15 L/kg. Product data sheet (93% CaCO3, D90 about 18 micron). Safety data sheet (egg-allergen label, dust-explosion test). Progress report January 2026 (export benchmark USD 600-1,000 a ton, development costs). Egyptian customs imports January-August 2026 (food grade about USD 690 a ton, medical about USD 1,065, other about USD 246). LIFE EGGSHELLENCE tile trials |
| Market data | USD/EGP about 54 (21 October 2026). Central Bank of Egypt overnight lending rate 20% (20 August 2026), so loans at 22%. Inflation 14.9% (July 2026). Online listings of membrane powder: about USD 3.5 to USD 100 a kg |
| Expert estimates | Equipment and building costs, staffing, utilities, selling prices (local and export), raw material costs, collection and payment terms |

Prices are entered in EGP per **ton** (the application rounds prices to whole numbers). Local price growth 8% a year, export 6%.

## Changes made after the first Excel review

1. First sales six months after the study start (July 2027), not three.
2. Capacity is shown in full in every year; the part-year and the ramp-up are in the share of capacity sold.
3. Seven years instead of five (the application allows up to 7).
4. Salaries 25% above the first estimate.
5. Yearly renewal capex per asset instead of one full replacement; routine maintenance lowered from 3% to 2% of revenue so the two do not overlap.
6. Pre-opening payroll is included in the training and commissioning expense (the application counts salaries only from the operation start).
7. Eggshell stock is 0 days (the shell is processed the day it arrives); the stock-day lists of the application only allow 0, 15, 30, 45 ... days.

## How to check that it worked

A 5-year Excel built with exactly the same assumptions gives these figures for 2027-2031 (EGP million). The application's
figures for the same years should match them closely (small differences come from rounding and from the years after 2031):

| | 2027 | 2028 | 2029 | 2030 | 2031 |
|---|---|---|---|---|---|
| Revenue | 6.8 | 32.2 | 47.5 | 59.0 | 67.5 |
| Gross profit | -0.6 | 9.0 | 19.5 | 27.1 | 31.9 |
| EBITDA | -6.0 | 2.0 | 12.6 | 20.2 | 23.7 |
| Net profit | -11.1 | -10.4 | 0.6 | 7.1 | 10.6 |

The balance sheet must balance. NPV, IRR and payback in the application cover seven years, so they will differ from the 5-year Excel.

## Things to know

- A cost **per unit** cannot grow every year in the application (only fixed monthly amounts can). The delivery cost is therefore EGP 1,800 a ton: the 2027 cost of about EGP 1,500 plus the average inflation of the seven years.
- Raw material costs are a % of the selling price. They were worked out year by year from the cost per kg of product, rising 9% a year (`SkyHealthySeeder::recipePercentages`).
- The What-if screen holds a saved downside case: powders -15% sales, membrane -50%, raw materials +5% / +10%, slower collections.

## To confirm with Sky Healthy Food

Real tons of eggshell a day and what Sky earns or pays for shell today; firm equipment quotations; letters of intent for membrane powder;
which product is sold (with membrane protein and an egg-allergen label, or without); moisture target (process flow below 1%, data sheet up to 3%, exporters 0.2%);
existing space, utilities, forklifts and laboratory that could lower the capex; financing; dust-explosion protection (minimum ignition energy test, ASTM E2019).

## Files

`database/seeders/SkyHealthySeeder.php`, `tests/Feature/SkyHealthySeederTest.php`, this document.
