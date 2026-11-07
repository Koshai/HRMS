# HRMS Portal

Bangladesh-first staff portal built with Laravel 13, Fortify, Livewire, Blade, and Tailwind CSS.

The [user guide](docs/user-guide/portal.md) is also available in the portal sidebar. Update it in the same change whenever a user-facing workflow or navigation label changes.

## Local setup

Use PHP 8.3 or later in Laravel 13’s supported range, Composer 2, Node.js 24, and npm. Enable `pdo_sqlite` for local development and tests or `pdo_mysql` for MySQL or MariaDB.

1. Run `composer install` and copy `.env.example` to `.env`.
2. For SQLite, set `DB_CONNECTION=sqlite`, remove the other `DB_*` entries, and create an empty `database/database.sqlite` file. For MySQL or MariaDB, set `DB_CONNECTION=mysql` or `mariadb` and provide a dedicated database and its credentials in `.env`.
3. On a new installation, run `php artisan key:generate`. Run `php artisan migrate` against the configured database.
4. Run `npm ci` and `npm run build`. Start the local server with `php artisan serve` and open `http://localhost:8000`.

Run `php artisan test` for the suite with in-memory SQLite. `composer test` adds Pint and PHPStan checks. Set `APP_LOCALE=bn` to use the initial Bangla dashboard strings. The display clock defaults to `Asia/Dhaka` and database sessions use UTC.

Attendance uses server timestamps for employee punches. Manual entries and correction proposals default to a 90-day lookback and a five-minute future allowance; configure these with `HRMS_ATTENDANCE_MANUAL_PAST_DAYS` and `HRMS_ATTENDANCE_FUTURE_MINUTES`. Work dates and local input use the effective assignment location's IANA time zone. Configure a working mail transport before sending staff invitations. GitHub CI runs attendance feature tests against MySQL 8.4 using the dedicated `phpunit.mysql.xml` configuration; that test database is disposable.

## Staff access

Provision accounts from a trusted terminal using `php artisan hrms:create-user "Site Admin" admin@example.invalid --role=admin`. Replace the example address with the authorized staff member’s email. The supported roles are `admin`, `hr`, `manager`, and `employee` (the default).

The staff member sets their password via **Forgot password**, signs in, and verifies their email. Configure a mail transport for shared environments. For local testing with `MAIL_MAILER=log`, retrieve the links from the private application log and treat them as secrets.

## cPanel deployment

- Confirm the domain can serve **only** the app’s `public/` directory. Point the document root to a path such as `/home/ACCOUNT/hrms-portal/public` and preserve `public/.htaccess` on Apache. Keep `.env`, source files, logs, and private documents outside every web root. Store private uploads in `storage/app/private`. [Laravel deployment](https://laravel.com/docs/13.x/deployment) · [cPanel Domains](https://docs.cpanel.net/cpanel/domains/domains/)
- Use HTTPS, a supported MySQL or MariaDB server, and PHP 8.3+ for both web and CLI with Laravel's required extensions and `pdo_mysql`. Set `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, and production database and mail settings. Allow the PHP process to write to `storage/` and `bootstrap/cache/`.
- Build assets with `npm ci && npm run build` on the build machine and deploy `public/build/` with the release. Install runtime dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`, check them with `composer check-platform-reqs --no-dev`, and run `php artisan migrate --force` and `php artisan optimize`. Generate the production app key once and preserve it across releases. Check `/up`, `/login`, and unauthenticated access to `/dashboard`.
- When jobs are introduced, use cPanel cron for `php artisan schedule:run` and schedule bounded database queue drains with `queue:work database --stop-when-empty --max-time=50` and overlap protection. Use the host’s PHP CLI binary and the app’s absolute path in cron. [Laravel scheduling](https://laravel.com/docs/13.x/scheduling)
