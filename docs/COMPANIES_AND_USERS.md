# Companies, people and sharing — Step C0

Modelled on El Tara: one database, many companies, each company sees only its own data.

## Who is who
| Role | Belongs to | Can |
|---|---|---|
| **Super admin** (platform owner) | no company | create companies, set status / user limit / subscription dates, give a company's person a new password. Does **not** work with studies. |
| **Company admin** | one company | add / edit / suspend the company's people; sees, edits, shares, copies and deletes **every** study of the company |
| **User** | one company | creates studies; sees own studies and those shared with him |

## Company
- Status: **trial**, **active**, **suspended** (suspended = nobody of the company can sign in; people already signed in are signed out on their next click).
- **User limit**: active accounts allowed, the administrator included. Suspended people do not count. The limit can't be set below the people already active.
- **Subscription end date**: after that day the company is **read-only** (look, copy nothing, change nothing). The last day itself still works. No end date = never read-only. A notice shows 30 days before and after the end.
- Companies are never deleted — suspend them.

## Sharing a study
Owner or company admin chooses, for each colleague of the same company: no access / can view / can view and edit.
- View: opens the study (all fields locked), may copy it (the copy belongs to the copier).
- Edit: may change the study. Never delete or re-share it.
- Shares are not copied with a copy.

## Accounts
- The creator types the first password (10+ characters, capitals, small letters, number). The person can change it with "Forgot password" (needs working e-mail); the super admin can always set a new one from the company page.
- People are suspended, never deleted, so their studies stay.
- You cannot suspend or demote yourself.

## Where it lives
Migration `2026_10_27_000028_create_companies_and_study_sharing.php` (companies, users.company_id/role/job_title/created_by, projects.company_id, project_shares).
Rules: `User::accessDenialReason()`, `Project::accessFor()`, `ProjectPolicy`, middleware `super`, `company`, `company-admin`, `read-only`.
Screens: Admin/Companies/Index + Form, Users/Index + Form, Projects/Index (share window).
Tests: CompaniesTest, CompanyUsersTest, StudyAccessTest, DatabaseStructureTest.
