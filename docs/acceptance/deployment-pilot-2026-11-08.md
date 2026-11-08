# Deployment and controlled pilot acceptance — 2026-11-08

Only fictional data and disposable test databases are in scope. Keep this record distinct from a production-host qualification.

| Gate | Evidence | Status |
| --- | --- | --- |
| Read-only environment preflight | `hrms:preflight` checks PHP/extensions, production security settings, mail configuration, built assets, writable runtime dirs, DB connection and migration status; focused tests cover passing fixture, blocked local config and DB outage. No secret value is printed. | PASS — automated command and tests; actual cPanel host OPEN |
| Repeatable release | [Runbook](../operations/deployment.md) pins build/deploy sequence and public-only document root. CI builds frontend assets and runs PHP tests. | PASS — recipe; host installation OPEN |
| Fresh MySQL 8.4 migration | [CI run 42450682586](https://github.com/Koshai/HRMS/actions/runs/42450682586) applied all migrations to a disposable MySQL service, then passed 60 targeted tests / 442 assertions. | PASS — disposable CI; target cPanel installation OPEN |
| Backup/restore | The same CI run dumped fictional MySQL `hrms_test` with a consistent snapshot, restored into distinct `hrms_restore_test`, and matched 27 table counts, SHA-256 row digests and selected references. The independent-process harness reported zero deadlocks or timeouts. | PASS — disposable CI rehearsal; cPanel-specific restore OPEN |
| Invitations and account lifecycle | Automated tests verify recipient, foreign-company resend denial, 60-second resend throttle, reset expiration and single use. Existing tests cover verification, inactive accounts and role boundaries. | PASS — automated; actual mail transport OPEN |
| Real-browser compliance and role journeys | Permitted browser surface remained unavailable under the previously recorded automatic usage limit. [Manual steps](../operations/deployment.md#manual-browser-acceptance-when-automation-is-unavailable) specify role, tenant, mobile, keyboard, compliance and fictional payroll checks. No browser interaction has been observed for this milestone. | **OPEN** |
| Statutory controls | Activation remains disabled; unresolved rules cannot be attested, and preview statutory estimates remain `null`. | PASS — existing automated safeguards; legal sign-off OPEN |

## Release decisions

| Scope | Decision | Outstanding proof |
| --- | --- | --- |
| Fictional isolated staging | **Conditional GO for rehearsal only** | Target host PHP web/CLI parity, secure document root/TLS, protected mail capture, preflight, host restore and role browser checks |
| Restricted pilot with real staff data | **NO-GO** | Privacy/security review, company policy validation, mail/host/restore acceptance, operations approval |
| Real statutory payroll | **BLOCKED** | Qualified Bangladesh source/applicability/numeric sign-off, separately approved handlers and controlled rollout |

No production host, real employee database, actual mail delivery, bank transfer or government filing was accessed. Automated HTTP checks must not be reported as browser acceptance.

Local gates and the CI application job passed: **126 application tests / 677 assertions**, Pint, PHPStan and frontend build. Local Blade compilation passed. A local read-only `hrms:preflight` invocation correctly returned nonzero for development settings, SQLite, log mail and pending local migrations; it made no repair. All automated results above apply to code commit `06f7be1` and [CI run 42450682586](https://github.com/Koshai/HRMS/actions/runs/42450682586).
