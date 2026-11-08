# Isolated Playwright browser tests

Run from the Laravel application directory after `composer install`, `npm ci`, `npm run build`, and `npx playwright install chromium`:

```powershell
$env:E2E_PHP = (Resolve-Path ..\.hrms-tooling\php\php.exe).Path # omit when php is on PATH
npm run test:e2e
npm run test:e2e:report
```

The runner creates a unique ignored SQLite database, a loopback Laravel server, five fictional accounts in two fictional companies, a pending profile, and a private captured-mail log. It creates a random password and application key, then removes its run directory. It refuses a non-testing application, a non-isolated database, external mail, or enabled statutory activation. Do not point it at a long-lived demo, staging, pilot, or production database.

CI runs the same specs against a dedicated `hrms_e2e_test` MySQL service in a separate job. Existing PHP and MySQL jobs remain independent. The HTML and JUnit reports are written under ignored `playwright-report/` and `test-results/`. Failure traces, screenshots and videos are retained for seven days in the CI artifact; they contain fictional data and may include one-time invitation links. Limit artifact access accordingly. For local debugging only, `E2E_KEEP_RUN=1` retains the ignored fictional database and mail log; delete that directory after inspection.

The [155-case coverage backlog](../acceptance/playwright-coverage-2026-11-08.md) distinguishes local passes, failures, external blockers and cases not implemented. Browser automation does not complete target-host qualification or Bangladesh legal review. Stateless payroll preview and statutory activation remain restricted by the application.
