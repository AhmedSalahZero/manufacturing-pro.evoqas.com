# Sky Healthy Food - SKY-CAL eggshell calcium plant, 7 products (version 2, 11 November 2026)

A seven-year study for a new plant inside Sky Healthy Food, which already runs a pasteurized-egg factory (so it already has the main raw
material: eggshell, about 93-95% calcium carbonate, captive waste at zero cost). The figures are the ones of the Excel workbook
`SKY-CAL_Feasibility_V2.xlsx`. Made with the same forms, checks and calculations as any study typed in by hand.

## Run it (Windows, from the project folder)

    composer dump-autoload
    php artisan db:seed --class=SkyHealthySeeder

Safe to run again: it only adds the study if it is not there yet. If the first (3-product) study of Sky is in the database it is **deleted** and replaced by this one, so only one Sky study is left.
Tests: `php artisan test --filter=SkyHealthySeederTest`.

**Company and login.** The seeder creates the company **Sky Healthy Food** and its administrator (only if missing): `admin@sky.com` / `Sky@Zavero2026`.
The demo company is never used or changed. No public link is made.

## The study in short

| | |
|---|---|
| Study | Sky Healthy Food - SKY-CAL Eggshell Calcium Plant (7 products, version 3); new business inside Sky; EGP; sector Food & Beverage |
| Period | January 2027 - December 2033 (7 years); installation during 2027; operation from January 2028 (6 operating years) |
| Products (in this order) | 1 Food Ingredient Grade (400 t/yr, from Apr 2028), 2 Supplement Active Grade (300, Jul 2028), 3 Oral Care Abrasive Grade (400, Jan 2028), 4 Cosmetic Grade (100, Jul 2028), 5 Eggshell Membrane Powder (35, Apr 2028), 6 Nutraceutical Raw Material (300, Jul 2028), 7 Collagen Hydrolysate (13, Jul 2028). The What-if screen covers the first five, so the membrane powder is the fifth |
| Share of capacity sold | The same for every product: 25 / 50 / 60 / 70 / 80 / 85 % for 2028-2033 (management decision; 2028 is 25% because the first year is a part year; 90% from 2034 if the study is extended) |
| Local channels | Food and supplement manufacturers (55%, price +3%) and Distributors (45%, price -3%, own collection terms 30% cash, 70% after 45 days) |
| Export (from 2029) | Saudi Arabia 60% and United Arab Emirates 30% of the export quantity, in USD; Germany 10%, in EUR. 2028 is local sales only |
| Local / export split of each product | Local 70 / 60 / 50 / 40 / 30 / 50 / 30 % once export starts (100% local before) |
| Prices | USD per ton, the same in every market: 450 / 550 / 200 / 400 / 10,500 / 650 / 25,000. 2028 is the base price. Local price = USD price x 56, +15% a year from 2029. Export: one price per product, the same in every market (the highest: the same number in USD and EUR, e.g. USD 450 = EUR 450, as in the Excel workbook), also +15% a year from 2029 |
| Exchange rates | Fixed in every year: USD/EGP 56 and EUR/EGP 60 (management decision; no forecast is used because none comes with a credible source) |
| Staff | 20 positions, 38 people (26 from January 2028, 12 from July 2028). Net salaries; the application adds 20% tax and 15% insurance, so the employer cost equals the gross salary of the client file; +12% a year |
| Raw materials | 10 materials, each entered as a % of the selling price (constant over the years), e.g. Food 12.4%, Oral care 28.0%, Membrane powder 2.9% |
| Expenses | 18 lines: 2 pre-operating one-time (FSSC 22000, pre-start payroll and training), electricity and gas per ton, fixed monthly costs growing 10% a year, freight 4.4%, commissions 1.5%, other marketing 4% of revenue |
| Fixed assets | 9 groups, EGP 80.1 million: site fit-out (construction, S-curve), five machine groups (30% at the order / 60% at delivery / 10% when ready; delivered two months before they are ready; ready between October and December 2027), laboratory, vehicles and IT (other). Loan of 30% (22%, 12 months grace, 5 years) on the three main lines |
| Return required on equity | 25%; tax 22.5%; perpetual growth 3% |

## Exchange rates

Fixed in every year at USD/EGP 56 and EUR/EGP 60. Version 2 used the IMF projection of October 2026 (converted to calendar years, with the last two years extended by a method choice) and EUR/USD 1.12; management replaced it with fixed rates. If a credible forecast is wanted later (for example an IMF report on Egypt), enter it in the study's exchange-rate table and name the source here. The What-if screen can still test a stronger or weaker pound.

## How to check that it worked

Revenue (EGP million) from the Excel workbook SKY-CAL_Feasibility_V4 (sheet Sales_Plan row 75). The application should agree closely; small differences come from the rounding of prices:

| | 2027 | 2028 | 2029 | 2030 | 2031 | 2032 | 2033 |
|---|---|---|---|---|---|---|---|
| Revenue | 0 | 19.0 | 43.8 | 60.4 | 81.1 | 106.5 | 130.2 |

Fixed assets total EGP 80,113,981. The balance sheet must balance. Cost, EBITDA and net profit of the application are fuller than the simplified check sheet of the Excel, so only revenue and capex are compared one-to-one.

## What changed from the first version

1. Seven products instead of three; operation starts January 2028 (was July 2027).
2. Export to Saudi Arabia and UAE (USD) and Germany (EUR), from 2029; yearly exchange rates from the IMF series (was USD 54 +6% a year and EUR = USD x 1.08).
3. Export prices flat in USD / EUR (was +6% a year): the depreciation of the pound is now explicit.
4. Fixed assets in nine groups with kinds (construction, machines, other) and milestone payments 30 / 60 / 10.
5. Net salaries from the workbook with 20% tax and 15% insurance (was first estimate +25%, 8% tax, 20% insurance).
6. Expenses and raw materials from the workbook (fixed costs +10% a year; recipes as % of price).
7. The What-if screen holds a downside case: -15% sales on the first four products, -50% on membrane powder, higher raw materials, slower collections and exchange rates 10% lower.

## Things to confirm

Rented or Sky-owned space; thermal-treatment unit; ICP-OES in-house or external; vendor quotes for the membrane separation unit and every machine marked placeholder; electricity tariff; the capacity ramp-up, price growth and collection terms (management assumptions); the loan instalment interval (quarterly assumed); EU entry for Germany (REACH, supplement and cosmetic rules, novel-food status of membrane products).

## Files

`database/seeders/SkyHealthySeeder.php`, `tests/Feature/SkyHealthySeederTest.php`, this document.

## Version 3 (12 November 2026): management changes to the assumptions

1. Share of capacity sold is the same for every product: 25 / 50 / 60 / 70 / 80 / 85 % for 2028-2033 (90% from 2034 if the study is extended).
2. Local and export prices both +15% a year, from 2029 (2028 is the base price).
3. One export price per product, the same in every market (the highest, the USD price).
4. Exchange rates fixed at USD 56 and EUR 60 in every year; the local base price now uses 56 (was the spot rate 54.70). Fixed assets (EGP 80.1 million) and the raw-material % of price follow the Excel workbook V4 at 56.
5. Version 3 replaces the earlier Sky Health studies: the seeder deletes version 2 (and the first 3-product study) so only one Sky Health case is left.
