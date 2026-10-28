# Demo company, demo user and two demo studies

Run once (safe to run again - it only adds what is missing):

    php artisan db:seed --class=DemoSeeder

| | |
|---|---|
| Company | Nile Foods Demo (active, 10 users, subscription 3 years) |
| Login | `demo@zavero.test` / `ZDemo@2026` (company administrator, English) |
| Currency | EGP |

## Study 1 - Delta Fresh Foods - New Factory (Demo)
A NEW company. Study 2027-01, 5 years; construction Jan-Mar 2027, operation from 2027-04.
4 products (orange juice, butter biscuits, canned fava beans, frozen mixed vegetables; the last starts selling in July 2027),
5 raw materials, 13 staff positions (direct labour split by typed shares), pre-operating and start-up expenses, 6 fixed assets
(bank loans on the building and the juice line, customised payment terms on the canning equipment), a what-if setting,
two dashboard notes and one sharing link ("Bank presentation").

## Study 2 - Nile Valley Foods - Expansion (Demo)
An EXISTING company. Study 2026-01, 5 years, operating from the first month.
3 products (tomato paste, pasta, mango juice) with finished goods in stock, 4 raw materials, staff already employed,
a pasta line and a tomato expansion in 2026, a warehouse and a fleet renewal later, and the opening balance form filled in:
fixed assets, cash, customers, other debtors, suppliers, accrued expenses, VAT and corporate tax, a 20 million bank loan,
a long-term supplier credit and equity. The opening balance sheet balances (retained earnings is the last figure).

## How it is made
`database/seeders/DemoSeeder.php` runs each form's own checks (the form requests) on the data and then saves it with the
same services the screens use, so the demo is exactly what a person would get by typing it in. If a figure were refused it
stops and says which form and which field. `tests/Feature/DemoSeederTest.php` checks the result (sign-in, both studies,
balanced opening balances, balanced statements, an IRR and an NPV on both dashboards, every screen opens).
