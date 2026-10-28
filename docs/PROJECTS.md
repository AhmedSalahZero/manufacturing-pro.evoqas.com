# Studies (Projects) — Step C1

What the screens do
- **My studies** (/projects): list, new study (name only), copy, delete.
- **Study form** (/projects/{id}/edit): study info, intro, general info (dates, tax, return rates), products, raw materials.

Rules (same as the previous ZAVERO unless listed under "Differences")
- Study ends in December of its last year. Months run from the start month to 7 calendar years (so later steps always have room).
- New company: operation starts on/after study start. Existing company: operation start = study start.
- Return rate needed from 2 years, perpetual growth rate from 3 years.
- Product selling start: from operation start until before study end. Stored as a month position (as before).
- Saving a *new company* removes any opening balances saved on the study.
- Removing a saved product deletes its data (recipe lines, allocations, sales pattern) and removes it from expenses.
- Copy = settings, products, raw materials, staff, expenses, assets, opening balances, saved results. Sharing links are not copied.
- Delete = hidden, kept in the database (can be restored).

Differences from the old application (on purpose)
1. Return/growth rate rule made consistent (old screen locked growth below 3 years yet required it from 2).
2. Access follows the company: see docs/COMPANIES_AND_USERS.md (owner, company administrator, colleagues the study is shared with).
3. Intro paragraph is a plain text box (not a rich editor).
4. Arabic names for the 24 business sectors are new — please review them.
5. Operation start must be before the study ends.

Files: app/Support/StudyCalendar.php (dates), app/Services/ProjectService.php (save/copy),
app/Http/Requests/*Project*Request.php (checks), app/Policies/ProjectPolicy.php, ProjectController.php.
Tests: tests/Unit/StudyCalendarTest.php (+ tests/Fixtures golden file from 9 real old studies), tests/Feature/ProjectsTest.php, tests/js/studyDates.spec.js.
