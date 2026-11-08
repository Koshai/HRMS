# Playwright 155-case coverage backlog

Source: private HRMS Playwright E2E Automation Test Plan (2026-11-08). Status reflects local Chromium run on 2026-11-08; CI and target-host acceptance are separate.

| ID | Priority | Scenario | Status |
| --- | --- | --- | --- |
| E2E-01-01 | P0 | Open sign-in page | PASSED (local) |
| E2E-01-02 | P0 | Open /register directly | PASSED (local) |
| E2E-01-03 | P0 | POST /register directly | NOT IMPLEMENTED |
| E2E-01-04 | P0 | Open protected /dashboard | PASSED (local) |
| E2E-01-05 | P0 | Sign in with correct credentials | PASSED (local) |
| E2E-01-06 | P0 | Sign in with incorrect credentials | PASSED (local) |
| E2E-01-07 | P1 | Submit blank / invalid email | NOT IMPLEMENTED |
| E2E-01-08 | P0 | Log out and revisit protected page | PASSED (local) |
| E2E-01-09 | P0 | Sign in with correct previous password | NOT IMPLEMENTED |
| E2E-01-10 | P0 | Profile becomes inactive while logged in | NOT IMPLEMENTED |
| E2E-01-11 | P0 | Sign in before verification | PASSED (local) |
| E2E-01-12 | P1 | Sign in using two-factor-enabled account | NOT IMPLEMENTED |
| E2E-01-13 | P1 | Enter invalid/reused two-factor code | NOT IMPLEMENTED |
| E2E-01-14 | P1 | Change own password in Security | NOT IMPLEMENTED |
| E2E-01-15 | P0 | Request forgot-password email | NOT IMPLEMENTED |
| E2E-01-16 | P0 | Reset password from valid token | NOT IMPLEMENTED |
| E2E-01-17 | P0 | Reuse expired token (after 60 minutes) | NOT IMPLEMENTED |
| E2E-01-18 | P1 | Edit email address | NOT IMPLEMENTED |
| E2E-01-19 | P1 | Exercise login throttling | NOT IMPLEMENTED |
| E2E-02-01 | P0 | Create employee record without account | PASSED (local) |
| E2E-02-02 | P0 | Create employee with duplicate number in same company | NOT IMPLEMENTED |
| E2E-02-03 | P1 | Create same employee number in different company | NOT IMPLEMENTED |
| E2E-02-04 | P0 | Create department, position, location | NOT IMPLEMENTED |
| E2E-02-05 | P0 | Assign employee to location and manager | NOT IMPLEMENTED |
| E2E-02-06 | P0 | Transfer employee from future date | NOT IMPLEMENTED |
| E2E-02-07 | P0 | Invite unlinked employee to portal | PASSED (local) |
| E2E-02-08 | P0 | Complete invitation with own password | PASSED (local) |
| E2E-02-09 | P0 | Invite same already linked employee | NOT IMPLEMENTED |
| E2E-02-10 | P0 | Try giving Admin role | NOT IMPLEMENTED |
| E2E-02-11 | P0 | Invite person with Admin role | NOT IMPLEMENTED |
| E2E-02-12 | P0 | Resend after controlled mail failure | NOT IMPLEMENTED |
| E2E-02-13 | P1 | Resend before throttle period | NOT IMPLEMENTED |
| E2E-02-14 | P0 | Invite email already taken | NOT IMPLEMENTED |
| E2E-02-15 | P0 | Try to invite a staff account | NOT IMPLEMENTED |
| E2E-02-16 | P1 | Deactivate employee | NOT IMPLEMENTED |
| E2E-02-17 | P1 | Try self-deactivation | NOT IMPLEMENTED |
| E2E-02-18 | P1 | Try editing Admin-linked profile | NOT IMPLEMENTED |
| E2E-03-01 | P0 | Check in with active location assignment | PASSED (local) |
| E2E-03-02 | P0 | Check out after check-in | PASSED (local) |
| E2E-03-03 | P0 | Double-click check in or retry same key | NOT IMPLEMENTED |
| E2E-03-04 | P0 | Check out before check in | NOT IMPLEMENTED |
| E2E-03-05 | P0 | Punch with no effective assignment | NOT IMPLEMENTED |
| E2E-03-06 | P1 | Leave punch open past 24 hours | NOT IMPLEMENTED |
| E2E-03-07 | P1 | Manually enter for direct report with reason | NOT IMPLEMENTED |
| E2E-03-08 | P0 | Manually enter for nonreport | NOT IMPLEMENTED |
| E2E-03-09 | P1 | Submit manual punch older than 90-day default | NOT IMPLEMENTED |
| E2E-03-10 | P1 | Submit punch over five minutes in future | NOT IMPLEMENTED |
| E2E-03-11 | P1 | Enter overnight and ambiguous DST local time | NOT IMPLEMENTED |
| E2E-03-12 | P0 | Propose attendance correction | NOT IMPLEMENTED |
| E2E-03-13 | P0 | Approve valid correction by different user | NOT IMPLEMENTED |
| E2E-03-14 | P0 | Attempt approval of own correction | NOT IMPLEMENTED |
| E2E-03-15 | P1 | Reject correction | NOT IMPLEMENTED |
| E2E-03-16 | P1 | Race punch/retry or correction approval | NOT IMPLEMENTED |
| E2E-04-01 | P0 | Create location-linked shift template | NOT IMPLEMENTED |
| E2E-04-02 | P0 | Publish dated weekday schedule | NOT IMPLEMENTED |
| E2E-04-03 | P0 | Attempt shift publication | NOT IMPLEMENTED |
| E2E-04-04 | P1 | Assign template at wrong location | NOT IMPLEMENTED |
| E2E-04-05 | P1 | Replace future schedule | NOT IMPLEMENTED |
| E2E-04-06 | P1 | Attempt overlapping schedule | NOT IMPLEMENTED |
| E2E-04-07 | P1 | Inspect overnight shift | NOT IMPLEMENTED |
| E2E-04-08 | P1 | Inspect no-shift day | NOT IMPLEMENTED |
| E2E-04-09 | P0 | Observe missed duty after grace | NOT IMPLEMENTED |
| E2E-04-10 | P1 | Observe work on rest day | NOT IMPLEMENTED |
| E2E-04-11 | P1 | Observe paid full-day approved leave | NOT IMPLEMENTED |
| E2E-04-12 | P1 | Observe half-day leave | NOT IMPLEMENTED |
| E2E-04-13 | P1 | Race shift publication with leave decision | NOT IMPLEMENTED |
| E2E-05-01 | P0 | Create leave type and dated policy | NOT IMPLEMENTED |
| E2E-05-02 | P0 | Grant yearly opening balance | NOT IMPLEMENTED |
| E2E-05-03 | P0 | Submit eligible leave request | PASSED (local) |
| E2E-05-04 | P0 | Submit overlapping full-day request | NOT IMPLEMENTED |
| E2E-05-05 | P1 | Submit complementary AM/PM half-days | NOT IMPLEMENTED |
| E2E-05-06 | P0 | Approve direct-report request if allowed | PASSED (local) |
| E2E-05-07 | P0 | Try to approve own leave | NOT IMPLEMENTED |
| E2E-05-08 | P0 | Approve request outside reporting tree | NOT IMPLEMENTED |
| E2E-05-09 | P0 | Reject request | NOT IMPLEMENTED |
| E2E-05-10 | P0 | Withdraw pending request | NOT IMPLEMENTED |
| E2E-05-11 | P0 | Cancel approved request with reason | NOT IMPLEMENTED |
| E2E-05-12 | P1 | Request across calendar year | NOT IMPLEMENTED |
| E2E-05-13 | P1 | Request across holiday/weekend | NOT IMPLEMENTED |
| E2E-05-14 | P1 | Submit more than available balance | NOT IMPLEMENTED |
| E2E-05-15 | P1 | Revise policy after historical approval | NOT IMPLEMENTED |
| E2E-05-16 | P1 | Race leave approval/cancellation | NOT IMPLEMENTED |
| E2E-06-01 | P0 | Create BDT payroll policy | PASSED (local) |
| E2E-06-02 | P0 | Create effective-dated compensation | PASSED (local) |
| E2E-06-03 | P0 | Try overlapping compensation version | NOT IMPLEMENTED |
| E2E-06-04 | P0 | Create monthly regular run | PASSED (local) |
| E2E-06-05 | P0 | Retry regular creation with different staff | NOT IMPLEMENTED |
| E2E-06-06 | P0 | Inspect unresolved attendance exceptions | NOT IMPLEMENTED |
| E2E-06-07 | P0 | Record neutral financial review with reason | NOT IMPLEMENTED |
| E2E-06-08 | P0 | Record approved overtime amount | NOT IMPLEMENTED |
| E2E-06-09 | P0 | Record approved unpaid amount | NOT IMPLEMENTED |
| E2E-06-10 | P0 | Add one-time bonus/deduction | NOT IMPLEMENTED |
| E2E-06-11 | P0 | Attempt approve own prepared run | NOT IMPLEMENTED |
| E2E-06-12 | P0 | Finalize HR-prepared completed-month run | NOT IMPLEMENTED |
| E2E-06-13 | P0 | Try finalize with unresolved or stale review | NOT IMPLEMENTED |
| E2E-06-14 | P0 | Release finalized payslips | NOT IMPLEMENTED |
| E2E-06-15 | P0 | Open own released payslip | NOT IMPLEMENTED |
| E2E-06-16 | P0 | Open another person's payslip or unreleased slip | NOT IMPLEMENTED |
| E2E-06-17 | P0 | Record two fictional partial payments | NOT IMPLEMENTED |
| E2E-06-18 | P0 | Retry payment key with changed amount | NOT IMPLEMENTED |
| E2E-06-19 | P0 | Attempt payment total above net | NOT IMPLEMENTED |
| E2E-06-20 | P0 | Download restricted reconciliation CSV | NOT IMPLEMENTED |
| E2E-06-21 | P0 | Create supplemental correction after finalization | NOT IMPLEMENTED |
| E2E-06-22 | P1 | Race approval/payment/recalculation | NOT IMPLEMENTED |
| E2E-07-01 | P0 | Create new compliance pack | PASSED (local) |
| E2E-07-02 | P0 | Create two pack versions | NOT IMPLEMENTED |
| E2E-07-03 | P0 | Record provisional employee applicability | NOT IMPLEMENTED |
| E2E-07-04 | P0 | Attempt overlapping applicability | NOT IMPLEMENTED |
| E2E-07-05 | P0 | Submit evidence reference and valid SHA-256 | NOT IMPLEMENTED |
| E2E-07-06 | P0 | Submit malformed digest | NOT IMPLEMENTED |
| E2E-07-07 | P0 | Try foreign-company rule/source association | NOT IMPLEMENTED |
| E2E-07-08 | P0 | Submit pack for legal review | PASSED (local) |
| E2E-07-09 | P0 | Attempt legal attestation with unverified rules | PASSED (local) |
| E2E-07-10 | P0 | Attempt statutory activation | NOT IMPLEMENTED |
| E2E-07-11 | P0 | Preview fictional payroll repeatedly | NOT IMPLEMENTED |
| E2E-07-12 | P1 | Inspect compliance checklist on mobile | NOT IMPLEMENTED |
| E2E-07-13 | P1 | Try compliance page URL | NOT IMPLEMENTED |
| E2E-07-14 | P1 | Try compliance pack/evidence URL from other tenant | NOT IMPLEMENTED |
| E2E-07-15 | P1 | Upload evidence on second company when allowed by own scope | NOT IMPLEMENTED |
| E2E-08-01 | P0 | Open first-company employee URL | PASSED (local) |
| E2E-08-02 | P0 | POST first-company employee update | NOT IMPLEMENTED |
| E2E-08-03 | P0 | Open first-company payroll run URL | NOT IMPLEMENTED |
| E2E-08-04 | P0 | POST first-company payroll review/adjustment | NOT IMPLEMENTED |
| E2E-08-05 | P0 | Open first-company payslip URL | NOT IMPLEMENTED |
| E2E-08-06 | P0 | Download first-company payroll CSV | NOT IMPLEMENTED |
| E2E-08-07 | P0 | POST first-company compliance evidence | NOT IMPLEMENTED |
| E2E-08-08 | P0 | Open salary/compensation routes directly | NOT IMPLEMENTED |
| E2E-08-09 | P0 | Open HR/Admin routes directly | PASSED (local) |
| E2E-08-10 | P0 | Open protected compliance/payroll pages | NOT IMPLEMENTED |
| E2E-08-11 | P0 | Open protected pages with old session | NOT IMPLEMENTED |
| E2E-08-12 | P1 | Probe file URLs for .env, storage, source | NOT IMPLEMENTED |
| E2E-09-01 | P0 | Keyboard login with Tab, Enter | PASSED (local) |
| E2E-09-02 | P0 | Blank required form submission | NOT IMPLEMENTED |
| E2E-09-03 | P1 | Every user-edit form with invalid input | NOT IMPLEMENTED |
| E2E-09-04 | P1 | Mobile navigation at 390x844 | NOT IMPLEMENTED |
| E2E-09-05 | P1 | Complex payroll/compliance forms on mobile | NOT IMPLEMENTED |
| E2E-09-06 | P1 | Switch EN/BN strings | NOT IMPLEMENTED |
| E2E-09-07 | P1 | Bangla glyphs and BDT format | NOT IMPLEMENTED |
| E2E-09-08 | P1 | Light/dark mode | NOT IMPLEMENTED |
| E2E-09-09 | P1 | Chrome desktop smoke | PASSED (local) |
| E2E-09-10 | P1 | Firefox desktop smoke | NOT IMPLEMENTED |
| E2E-09-11 | P1 | WebKit smoke | NOT IMPLEMENTED |
| E2E-09-12 | P1 | No unlabeled buttons/inputs on core flows | NOT IMPLEMENTED |
| E2E-09-13 | P2 | Browser back/forward and page refresh | NOT IMPLEMENTED |
| E2E-09-14 | P1 | Expired session mid-edit | NOT IMPLEMENTED |
| E2E-10-01 | P0 | Run preflight in deliberately unsafe configuration | NOT IMPLEMENTED |
| E2E-10-02 | P0 | Run preflight on staging host | BLOCKED (host/owner) |
| E2E-10-03 | P0 | Verify web vs CLI PHP versions/extensions | BLOCKED (host/owner) |
| E2E-10-04 | P0 | Check TLS and public/ document root | BLOCKED (host/owner) |
| E2E-10-05 | P0 | Run fresh MySQL migrations in disposable database | NOT IMPLEMENTED |
| E2E-10-06 | P0 | Restore fictional backup into different DB | BLOCKED (host/owner) |
| E2E-10-07 | P0 | Verify invitation/reset email in captured mailbox | NOT IMPLEMENTED |
| E2E-10-08 | P0 | Check staging health and production-like settings | NOT IMPLEMENTED |
| E2E-10-09 | P1 | Exercise failed deploy without destructive rollback | BLOCKED (host/owner) |
| E2E-10-10 | P1 | Verify no test credentials/screenshots/secrets leaked | NOT IMPLEMENTED |

Summary: 24 PASSED locally, 0 FAILED in final local run, 5 BLOCKED by external host/owner acceptance, 126 NOT IMPLEMENTED. A passing browser scenario does not replace legal sign-off.
