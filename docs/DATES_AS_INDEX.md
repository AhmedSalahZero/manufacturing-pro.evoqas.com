# Rule: every date inside a study is saved as a position (index)

* The **study start date** is the only real date kept as a date (the anchor). Everything else is a position counted from it: 0 = the study's first month, 3 = three months later.
* Saved this way: the **operation start** (projects.operation_start_date), each product's **selling start**, and — from the next stages — staff start dates, expense start dates, asset purchase dates, loan start dates, etc.
* Monthly results are keyed by month position, yearly results by year position (0 = first calendar year).
* On screen people still pick a month ("April 2027"). The server turns it into a position when saving (`StudyCalendar::indexOf`) and back into a month when loading (`StudyCalendar::monthAt`).
* The study form moves the operation start and all selling starts by the same number of months when the study start is changed, so the positions stay the same and the whole study moves as one.
* Because the monthly figures are shared out per calendar year, moving the study start can change how a first partial year is split (for example selling starts at position 6: from July when the study starts in January, from September when it starts in March). The yearly target is still sold in full.
