# Browser acceptance record — 2026-11-08

Environment: local PHP 8.5 server and a copied, fictional SQLite demo database. The original demo database was not changed. Browser: Codex in-app Chromium, desktop default viewport and 390 × 844 mobile viewport. Financial actions in this record are view-only.

| Journey | Evidence | Result |
| --- | --- | --- |
| Admin sign-in and payroll | Nadia signed in; dashboard showed Admin, payroll policy, compensation, draft October run, calculated entry, approval control and restricted CSV link. | PASS |
| HR sign-in and payroll | Farhana signed in; HR dashboard and payroll policy, compensation and runs rendered. | PASS |
| Manager team exceptions | Imran signed in; team exceptions page explained fact-only access and displayed no visible candidates. Direct `/admin/payroll` returned 403. | PASS |
| Employee own payslips | Ayesha signed in; own payslips initially showed no released slips. Admin payroll link was absent; direct `/admin/payroll` returned 403. After release, she opened the October 37,500.00 BDT payslip and saw the 1,000.00 manual payment. | PASS |
| Separate approval, release and reconciliation | Nadia finalized the HR-prepared October run, released payslips and recorded a fictional 1,000.00 BDT manual payment. The page showed paid 1,000.00 and outstanding 36,500.00. The restricted CSV downloaded with minor-unit values 3,750,000 net, 100,000 paid and 3,650,000 outstanding. | PASS |
| Employee mobile navigation | At 390 × 844, dashboard cards fit the viewport. Navigation button opened the sidebar with employee-only links. | PASS |
| English/Bangla fields, keyboard focus and validation | Not completed in this pass. | OPEN |
| HR create and calculate a new run, plus validation errors | Not completed in this browser pass; covered by feature tests where noted. | OPEN |
| Inactive, unverified and foreign-company browser sessions | Not completed in the browser; negative authorization feature tests exist. | OPEN |

This is a partial browser acceptance record. PASS means the named interaction was observed in a real browser; it does not imply the untested journeys passed. The acceptance database and its test-only passwords live under ignored local tooling and are not committed.
