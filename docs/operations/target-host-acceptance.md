# Target-host staging acceptance worksheet

**OPEN — no selected or authorized staging host has been supplied.** An authorized operator should use fictional data only and keep the actual domain, account, database names, credentials, reset links, backups and screenshots in private custody. Record only sanitized PASS/FAIL/OPEN outcomes in the tracked [acceptance record](../acceptance/deployment-pilot-2026-11-08.md). The [deployment runbook](deployment.md) governs release packaging and rollback.

## 0. Confirm the target

Privately record operator, approver, time, hosting provider/plan, staging subdomain, approved release SHA, dedicated disposable source and restore DB identifiers, controlled mail capture, access restriction, backup location, and agreed recovery-point/restore-time targets. Confirm both databases are disposable and this is not a production host. **Stop if any item is missing.** Compare the deployed release SHA or archive SHA-256 with the approved artifact; do not assume the latest `main` was deployed.

## 1. Runtime and routing checks

1. Run `php -v` and `php -m` with the exact CLI binary used for Artisan. In cPanel's PHP controls, record the **web** PHP version and extensions for this subdomain; compare `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `dom`, `xml`, `session`, `tokenizer`, `ctype`, `filter`, `hash` and `pcre`. Do not publish `phpinfo()` output. Record MySQL/MariaDB version and the dedicated staging DB user's permissions.
2. Verify in the host panel that the domain root resolves to the release's `public/` and preserves `public/.htaccess`. Check a valid HTTPS certificate and HTTP-to-HTTPS redirect. Visit `/up` and `/login`; unauthenticated `/dashboard` must redirect to login. If `public/` cannot be the sole web root, mark the host **incompatible**.
3. Place a random **non-secret** marker outside `public/`, request its corresponding URL with a normal GET sent to `/dev/null`, record 404/403, then remove the marker. Inspect status-only HEAD requests for `.env`, `.git/HEAD`, source PHP, `storage/logs/laravel.log`, a backup name and a private evidence path. Example: `curl -sS -I "https://<approved-host>/.env"`. Never print response bodies. Any 200 on a private path is FAIL; restrict access before proceeding.
4. On the explicitly confirmed disposable staging DB only, perform the runbook's first install or upgrade. After `php artisan migrate --force`, run `php artisan migrate:status` and `php artisan hrms:preflight`. Record every PASS/BLOCK label and exit code, not `.env` values. Confirm `public/build/manifest.json` loads, runtime directories are writable, `APP_KEY` fingerprint remains unchanged across redeploys, and statutory activation stays disabled. A passing CLI preflight does **not** prove web PHP parity, TLS or mail delivery.

## 2. Off-source restore and rollback proof

Create fictional records across users, assignments, attendance, leave balance, payroll entries/payments and compliance evidence. Record source table counts and representative SHA-256 row digests privately. With protected database credentials, make a consistent `mysqldump --single-transaction` or equivalent backup outside every web root. Check exit code, nonzero size and backup SHA-256. Restore into a **different, newly created disposable database**; never overwrite the source. Compare migration count, key table counts/digests and selected foreign references, including finalized payroll and append-only audit rows. Record backup reference, checksum, restore duration, operator, agreed recovery targets and deviations. A mismatch is FAIL; an unattempted host restore is OPEN. The previous [CI rehearsal](../acceptance/deployment-pilot-2026-11-08.md) is a model, not host proof.

Simulate a failed **code-only** release on this disposable host: keep maintenance mode, switch to a known schema-compatible previous artifact, rebuild caches, rerun preflight and verify fictional financial/audit hashes unchanged. If a migration changed schema, stop for a written recovery decision; no blind rollback or reset.

## 3. Mail and browser observations

Use only approved protected staging mail capture with fictional recipients. Observe invitation receipt and HTTPS reset URL, resend throttle, reset expiry/single use, email verification, logout and inactive-session denial. Do not log or paste tokens. If safe delivery cannot be exercised, mark mail OPEN.

Run the [manual browser procedure](deployment.md#manual-browser-acceptance-when-automation-is-unavailable) on this exact host. For **each** role and case record browser/version, desktop and 390×844 viewport, time, role, expected/observed behavior, sanitized screenshot reference, and PASS/FAIL/OPEN. Test direct URL and foreign-company writes on disposable fixtures. Compliance must accept valid SHA-256 metadata as `supplied_unverified`, reject malformed metadata, block attestation/activation and keep the statutory preview `null` without changing finalized payroll. CI HTTP tests are not browser evidence.

## 4. Operator sign-off

| Gate | Result | Private evidence reference / operator / date |
| --- | --- | --- |
| Authorized host and disposable resources | OPEN | |
| Web/CLI PHP and extensions | OPEN | |
| `public/` root, TLS, private-path denial | OPEN | |
| Fresh migration and read-only preflight | OPEN | |
| Controlled mail lifecycle | OPEN | |
| Off-source host backup/restore and recovery targets | OPEN | |
| Role, tenant, compliance and mobile browser cases | OPEN | |
| Operations owner and rollback sign-off | OPEN | |

Fictional staging is **GO only after every host gate passes and the owner signs**. A restricted real-data pilot remains NO-GO pending separate privacy, policy, mail, access and operations approval. Statutory payroll remains BLOCKED pending qualified Bangladesh legal/payroll sign-off and a later approved calculation release.
