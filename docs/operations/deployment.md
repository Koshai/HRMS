# Deployment and controlled pilot runbook

This procedure is for a **fictional-data staging deployment**. A real host, live mail delivery, and a production database have not been exercised by CI. Use a named operations owner and record the release commit, hosting account, backup ID, migration batch, and acceptance decision in a private change record. Do not put credentials, employee data, reset links, or backup files in this repository or CI artifacts.

## Capability decision

| Capability | Current release classification | Gate before broader use |
| --- | --- | --- |
| Staff accounts, organization, attendance, scheduling | Restricted pilot candidate | Host, privacy, access, mail and role acceptance |
| Leave balances and approvals | Restricted pilot candidate | Company policy and opening balance validation |
| Payroll drafts, payslips, manual payment records | Fictional demonstration only | Separate financial/legal authorization and reconciliation |
| Statutory calculations, bank transfers, government filings | Blocked | Qualified Bangladesh sign-off and a later separately tested release |

## Host and release prerequisites

Use PHP 8.3+ with Laravel's required extensions and `pdo_mysql` in **both web and CLI PHP**; compare their versions and loaded modules. Supply supported MySQL/MariaDB, TLS, Apache rewrite, and a domain or subdomain whose document root is the release's `public/` directory. Laravel's [deployment requirements](https://laravel.com/framework/docs/13.x/deployment) and [cPanel Domains controls](https://docs.cpanel.net/cpanel/domains/domains/) are the host references; available cPanel options depend on the hosting provider. Do not point a domain at the project root or move `.env`, source, `storage`, backups, or the external private wiki under a web root. If the host cannot safely map to `public/`, classify it **incompatible**. Preserve `public/.htaccess`; confirm rewrite rules and direct requests to `.env`, repository paths and storage cannot expose files.

On a compatible build machine, check out the pinned release commit, run `composer install --no-dev --prefer-dist --optimize-autoloader`, `composer check-platform-reqs --no-dev`, `npm ci`, and `npm run build`. Package code, `vendor/`, `public/build/`, lock files and `public/.htaccess` without `.env`, `node_modules/`, tests' generated data, log files, private wiki or backups. Node is not needed on the cPanel host. Verify the release archive checksum and permissions before switching the domain to it.

Use a dedicated staging subdomain and a dedicated least-privilege database user. Restrict access by IP or host authentication where possible, block indexing, and use fictional accounts with an isolated mail capture transport or an explicitly controlled staging mailbox. Never send fictional credentials or reset links to real recipients. Keep `APP_KEY` in secret custody and **preserve it** across releases and restore; changing it invalidates encrypted sessions and other encrypted data. Set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, database/cache/session drivers, SMTP or sendmail plus an authorized sender. `MAIL_MAILER=log` and `array` are unsuitable for a hosted pilot. Keep `.env` private and writable only by the intended account. Give the PHP process write access to `storage/` and `bootstrap/cache/`, not blanket write access to source or `public/`.

## First install and upgrade sequence

1. Prepare the isolated staging database and private `.env`; generate an app key **once** for a new installation only. For upgrades, restore the existing key through the secret store. Test the PHP CLI binary, host extensions and DB connection. Compare web PHP using the host panel or a temporary protected diagnostic, then remove that diagnostic.
2. Record release commit and migration status. For upgrades, take and verify the pre-migration database backup and private-file/config inventory below. Schedule a maintenance window; use `php artisan down` before schema changes. First installations have no existing traffic.
3. Deploy the verified release to a directory outside the web root, with the domain pointing to its `public/`. Run `php artisan migrate --force`. Never run `migrate:fresh`, `db:wipe`, demo seeders or an unreviewed migration rollback on a persistent database.
4. Run `php artisan optimize`, then `php artisan hrms:preflight`. It is read-only: it reports PHP/extensions, production settings, mail sender/transport, writable directories, frontend manifest, DB connection and pending migrations. A **BLOCK** result prevents promotion. It does not prove web/CLI parity, TLS routing, mail delivery or restore ability; check those manually. The command deliberately prints no secret values.
5. Run `php artisan up` after a successful upgrade. Check `/up`, `/login`, logout and an unauthenticated `/dashboard` redirect. Verify direct requests cannot expose private files. Sign in with a fictional Admin account and run the role/tenant checklist below. Check the configured queue strategy: this release's invitations are synchronous; do not assume a persistent queue worker on shared hosting.
6. Record the final go/no-go decision and keep the prior code release available until the rollback window closes. Clear/rebuild config and view caches on any safe retry; run `hrms:preflight` again.

## Backup, restore and failed-release response

Before upgrades, use a transactionally consistent MySQL backup (for example `mysqldump --single-transaction` for InnoDB) with a private option file or protected environment, never an inline production password. Include schema, migrations, users, organization, attendance events, leave ledger, scheduling, payroll snapshots/payments, compliance audit/evidence, and any private uploaded files. Encrypt backup storage, keep it off-host, restrict access, record checksum and retention, and keep `APP_KEY` in separate secret custody. Check backup size and exit status; an empty file is not proof.

Restore into a **different, newly created disposable database**, never the source. Compare table counts and cryptographic row digests for key business tables and inspect representative foreign-key references. The CI rehearsal uses fictional MySQL `hrms_test` → `hrms_restore_test`, compares 27 tables, and rejects empty key fixtures or orphaned records. It does not prove a particular cPanel provider's backup system; perform a host-specific restore before any pilot.

On a failed release, keep maintenance mode, preserve the failed state and logs privately, stop writes, and assess whether the migration changed schema. If code alone failed and the old code is schema-compatible, switch back to the prior release and rebuild caches. If schema or data changed, prefer a reviewed forward fix. Restoring a backup requires a separate recovery database, verified hashes, preserved `APP_KEY`, explicit downtime and written approval from the operations owner; do not overwrite live ledgers or run a blind migration rollback. Reconcile append-only attendance, leave, payroll payment and compliance events before reopening.

## Account, mail and tenant acceptance

Create the first fictional Admin through `php artisan hrms:create-user` only on the intended database. In the portal, invite fictional HR, then create locations, departments, positions, employees and dated reporting assignments. Configure shifts, leave policies, holidays and opening balances and validate company decisions before starting attendance. Public registration remains closed. Invitation uses a password-reset link; delivery failure leaves the account unverified and requires an authorized retry. Test sender/domain, HTTPS link, expiration, single use, resend throttle, email verification, inactive account logout, privilege changes and password reset with controlled mail capture. Treat reset links as credentials. Verify Admin, HR, Manager and Employee views and a second company's HR, including direct URL denial for payroll, compliance evidence and other-company records.

## Manual browser acceptance when automation is unavailable

Use an isolated fictional database and record browser/version, viewport (desktop and 390×844 mobile), role, timestamp, expected/observed result, screenshots without secrets, and before/after DB counts. Keep every unobserved case **OPEN**.

1. Sign in as each role; use keyboard Tab/Enter on login and forms, inspect validation error focus, switch English/Bangla, verify mobile navigation, logout and session expiry.
2. As HR, create organization and an employee with a dated manager assignment. Confirm manager sees only direct reports and Employee sees only self. Create a shift, attendance punch/correction, leave request/decision and unresolved attendance exception; verify it does not silently become a payroll deduction.
3. As HR, create a compliance pack. Confirm four unresolved rules and official links. Supply a fictional private evidence reference with a valid SHA-256, then a malformed digest; only the valid metadata should appear as `supplied_unverified`. Add provisional applicability, reject overlap/invalid dates, submit for review, and inspect readiness blockers.
4. As a separate Admin, attempt compliance attestation and activation. Both must fail without legally verified rules; status remains unchanged. Preview a fictional finalized payroll run twice: baseline amounts and DB rows remain identical, statutory estimate says **Not calculated** and is `null` in the response data, with warnings.
5. As another company's HR and as Manager/Employee, attempt direct compliance, salary and export URLs from company A. Record 404/403 as appropriate; hidden navigation alone is insufficient.
6. For fictional payroll only, prepare, independently finalize, release a payslip and record/reconcile a manual **fictional** payment. Do not initiate a bank transfer or apply statutory numbers.

## Incident checklist

The operations owner should restrict access, preserve private logs and a current backup, record release/time/affected tenant, and decide whether to keep maintenance mode. For failed mail, verify transport and sender without logging tokens; retry only authorized invitations. For database outage or full storage, stop writes, verify backup integrity and capacity, restore service, then reconcile append-only records. For unexpected payroll or tenant access, suspend affected accounts, preserve audit evidence and escalate before reopening. Keep `APP_DEBUG=false` on hosted environments; sanitize diagnostics before sharing.

## Readiness decisions

Fictional staging is a **conditional GO** only after the target host passes preflight, protected mail capture, routing and a separate-host restore. A restricted real-data pilot is **NO-GO** pending privacy/security assessment, company leave policy validation, live-mail testing, actual host acceptance and operations authorization. Real statutory payroll is **BLOCKED** pending qualified Bangladesh legal/payroll sign-off, approved calculation handlers and a later controlled release.
