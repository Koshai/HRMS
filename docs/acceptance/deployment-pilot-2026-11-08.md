# Deployment and controlled pilot acceptance — 2026-11-08

Only fictional data and disposable test databases are in scope. Keep this record distinct from a production-host qualification.

| Gate | Evidence | Status |
| --- | --- | --- |
| Read-only environment preflight | `hrms:preflight` checks PHP/extensions, production security settings, mail configuration, built assets, writable runtime dirs, DB connection and migration status; focused tests cover passing fixture, blocked local config and DB outage. No secret value is printed. | PASS — automated command and tests; actual cPanel host OPEN |
| Repeatable release | [Runbook](../operations/deployment.md) pins build/deploy sequence and public-only document root. CI builds frontend assets and runs PHP tests. | PASS — recipe; host installation OPEN |
| Fresh MySQL 8.4 migration | CI applies all migrations to a disposable MySQL service before targeted tests. | OPEN — new milestone CI pending |
| Backup/restore | CI will dump the fictional MySQL service and restore to a separate disposable database, comparing 27 table counts/digests and selected references. | OPEN — rehearsal CI pending; cPanel-specific restore OPEN |
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
