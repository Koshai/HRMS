# Browser acceptance record — 2026-11-08

Environment: local PHP 8.5 server and a copied, fictional SQLite demo database. The original demo database was not changed. Browser: Codex in-app Chromium, desktop default viewport and 390 × 844 mobile viewport. All payroll actions used fictional data in the isolated copy.

| Journey | Evidence | Result |
| --- | --- | --- |
| Admin sign-in and payroll | Nadia signed in; dashboard showed Admin, payroll policy, compensation, draft October run, calculated entry, approval control and restricted CSV link. | PASS |
| HR sign-in and payroll | Farhana signed in; HR dashboard and payroll policy, compensation and runs rendered. | PASS |
| Manager team exceptions | Imran signed in; team exceptions page explained fact-only access and displayed no visible candidates. Direct `/admin/payroll` returned 403. | PASS |
| Employee own payslips | Ayesha signed in; own payslips initially showed no released slips. Admin payroll link was absent; direct `/admin/payroll` returned 403. After release, she opened the October 37,500.00 BDT payslip and saw the 1,000.00 manual payment. | PASS |
| Separate approval, release and reconciliation | Nadia finalized the HR-prepared October run, released payslips and recorded a fictional 1,000.00 BDT manual payment. The page showed paid 1,000.00 and outstanding 36,500.00. The restricted CSV downloaded with minor-unit values 3,750,000 net, 100,000 paid and 3,650,000 outstanding. | PASS |
| Employee mobile navigation | At 390 × 844, dashboard cards fit the viewport. Navigation button opened the sidebar with employee-only links. | PASS |
| Bangla navigation and payslip list | With `APP_LOCALE=bn`, the employee dashboard, sidebar, clock label and own-payslip heading rendered in Bangla. The same released fictional payslip was listed. | PASS |
| Unverified employee session | Temporarily removing verification in the copied fixture redirected an existing session from own payslips to email verification. The original verification value was restored. | PASS |
| Inactive employee session and sign-in | Temporarily marking the fictional profile inactive redirected the session to sign-in and rejected a new sign-in with a generic credentials error. The profile was restored to active. | PASS |
| Keyboard focus and form validation | HR email → Tab focused password; Enter submitted sign-in. Blank pay-run month invoked native required-field focus. Submitting a month with no employees displayed “The employee ids field is required.” After the banner change, the real browser reported the alert as active and `document.activeElement` was the alert. | PASS |
| HR create and calculate a new run, plus validation errors | In the isolated copy, Farhana created September regular run for Ayesha, was denied self-approval, added a 5,000-poisha bonus, and calculated 3,755,000-poisha gross/net. Nadia finalized and released it. Ayesha opened her own 37,550.00 BDT payslip; the restricted CSV downloaded. | PASS |
| Foreign-company browser session | Added fictional Leena Das as HR of a second company in the isolated copy. Her payroll page listed only Leena, no policy or runs; her directory listed only Leena. Direct requests for company-one run `/admin/payroll/runs/2`, payslip `/payroll/payslips/2`, and Ayesha compensation `/admin/payroll/employees/4` each returned 404. | PASS for listed reads; cross-company writes remain feature-test only |
| Foreign HR mobile navigation | At 390 × 844, the navigation button opened a scrollable sidebar containing HR links. | PASS |

PASS means the named interaction was observed in a real browser. The acceptance database and its test-only passwords live under ignored local tooling and are not committed. Foreign-company write attempts remain covered by negative feature tests, not this browser session.
