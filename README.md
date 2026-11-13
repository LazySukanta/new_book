# POS & Inventory

Laravel 13 POS and inventory project. Release 1 has been built and rehearsed on staging; shop acceptance and the go-live decision remain pending. Local Release 2 work now includes supplier returns, branch transfers, historical/GST reporting, Excel exports and the owner audit viewer. Release 2 hardening is next. See `docs/MILESTONES.md` for verified slices and remaining gates; local changes are not automatically deployed.

## Layout

```text
application/          Laravel backend and website together
  app/                Application PHP code
  resources/views/    Website Blade views; future Livewire UI
  resources/css/      Website styles
  resources/js/       Website scripts
  routes/             Web session and versioned API routes
  tests/              MySQL-backed foundation and authentication tests
docs/
  SPEC.md
  SCHEMA.md
  RULES.md
mockups/              Existing illustrative UI mockup
task_remark/          Task map, completion remarks and test evidence
```

Read the three documents in `docs/` before implementation. Precedence: `RULES.md` > `SCHEMA.md` > `SPEC.md`.

## Local setup

Run commands from `application/`. Use PHP 8.3 or later with PDO MySQL, mbstring, OpenSSL, cURL, ZIP and GD (required by the Excel library), Composer 2, MySQL 8, Node and npm. On Windows, `npm.cmd` works in PowerShell.

1. Create `khatapos_dev` and `khatapos_test` in your local MySQL instance. Give each a separate login limited to its own database.
2. Copy `.env.example` to `.env` and `.env.testing`. Enter the local MySQL host, port and matching credentials in each. Set `APP_ENV=testing` and `DB_DATABASE=khatapos_test` in `.env.testing`. These files are ignored by Git.
3. Install and verify from the application directory:

```sh
composer install
php artisan key:generate
php artisan key:generate --env=testing
php artisan migrate
php artisan migrate --env=testing
php artisan test
npm ci
npm run build
php artisan serve
```

Open `/up` for the health check and `/` for the website. PHPUnit forces the MySQL test database name, and `TestEnvironmentTest` queries the connected database to verify it. Use the milestone ledger in `docs/MILESTONES.md` for actual pass/fail evidence.

## Owner and device setup

Run `php artisan shop:setup` locally after migrations. It prompts for the first owner's name, email and password, creates the owner and outputs one pairing code valid for 30 minutes. It refuses a second owner bootstrap. There is no seeded default password or public registration.

If the code expires before pairing, `php artisan shop:pair-device` requires the owner's email/password and approves a new browser for all active branches. An authenticated owner can instead approve a browser for selected branches using `POST /api/v1/devices` with `name` and `branch_ids`. Revoke unused devices using `DELETE /api/v1/devices/{id}`.

Browser pairing is temporarily disabled with `AUTH_BROWSER_PAIRING_REQUIRED=false`: email/password sign-in enrolls a browser automatically using the staff member's permitted branches. Set it to `true` and refresh the configuration cache to restore owner-approved pairing. Password-only browser sessions then require fresh pairing; normal API token enrollment still requires an approved one-time code.

Browser flow: GET `/auth/csrf`, then POST `/auth/login` with email, password and the CSRF token; include an initial `pairing_code` when browser pairing is enabled. Retain cookies. The server issues an encrypted HttpOnly device cookie and rotates the session. Later approved-browser logins use credentials and that cookie. GET `/auth/me` inspects the current session; POST `/auth/logout` ends it. Stateful API requests need the configured Sanctum origin and CSRF protection. Use HTTPS and secure cookies in deployment.

API demonstration: POST `/api/v1/auth/token` with email, password and a fresh approved pairing_code. A code can be consumed once, by either session enrollment or token enrollment. The returned bearer token expires after 30 days. Send it in Authorization to `GET /api/v1/auth/me`, or to `GET /api/v1/branch` with X-Branch-Id. POST `/api/v1/auth/logout` revokes that token. Send Accept: application/json. Token renewal/re-pairing currently requires fresh owner approval. Browser UI uses sessions, never localStorage bearer tokens.

All current device enrollment is for the web channel, derived server-side. Native apps, their channel provisioning and offline synchronization remain out of scope. Security events record metadata without credentials, pairing codes or token contents. Posting operations use audited transactions and idempotency.

## Staging on Hestia

Read [server access and deployment boundaries](docs/operations/SERVER_ACCESS.md) before connecting or deploying. SSH uses `ubuntu@51.222.29.250`; the Hestia site account is `kagajghar02`. Other applications and their databases must remain untouched.

The staging UI is at [pos.kagajghar.com](https://pos.kagajghar.com). Run build and Artisan commands from `application/`. Deploy a Git release with `npm ci` and `npm run build` assets, then run `composer install --no-dev --prefer-dist --optimize-autoloader`, `php artisan migrate --force`, `php artisan config:cache`, and `php artisan view:cache` as the site account. Run the access seeder when creating a fresh database and use `php artisan shop:setup` for first-owner enrollment.

The Hestia web root serves only Laravel's public assets and a front controller that loads the release from the domain's private directory. Keep the environment file and dependencies in the private release. The current PHP-FPM pool is PHP 8.3 with its own domain socket. HTTPS is forced and the app uses secure session cookies, database sessions, disabled debug output, and daily logs. The app user owns writable storage and bootstrap cache.

The staging instance uses a dedicated MySQL 8.4 service on localhost port 33319. Keep its application credentials in the private environment file and its root-only administration credentials outside the domain directory. The existing shared MariaDB instance serves other applications. The [backup timer and restore notes](task_remark/M37/task034.md) describe scheduling, retention, and reconciliation.

For an application rollback, keep the previous private release and its hashed assets, then switch the public front controller back to that release and verify the sign-in page and health route. Check database compatibility first. For a schema rollback or recovery, restore the matching backup to a separate schema, reconcile it, and point the recovered release at the verified schema during an authorized maintenance window.

## This Windows workstation

PHP is installed under `C:\Users\local User\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`. Composer is `C:\Users\local User\AppData\Local\KhataPOS\tools\composer.phar`; invoke it with PHP if Composer is absent from PATH.

The portable MySQL server uses `C:\Users\local User\AppData\Local\KhataPOS\mysql-local\my.ini` and port 33316. Its executable is under `C:\Users\local User\AppData\Local\KhataPOS\tools\mysql-8.4.9-winx64\bin\mysqld.exe`. To restart after Windows restarts, launch that executable with `--defaults-file="C:\Users\local User\AppData\Local\KhataPOS\mysql-local\my.ini"` using a hidden background process. Do not initialize or replace its existing data directory. Local environment files already contain separate development/test credentials.

Completion remarks and verification evidence are indexed in [task_remark](task_remark/README.md).

The [M14 manual test guide](task_remark/M14/manual-tests.md) covers purchase corrections. The [M17 notes](task_remark/M17/task017.md) cover sales pricing previews. Current regression counts and browser evidence are recorded in the [milestone ledger](docs/MILESTONES.md).

Excel exports preserve monetary decimals and identifiers as explicit text, protect formula-like user text, read reports in chunks and reject results above 50,000 rows. A private per-export cache and an available-memory check protect the request. Apply narrower filters if a wide report exceeds the available memory. Export files and cell caches stay outside the public web root. Before the next deployment, verify GD is enabled for the site's PHP CLI and FPM runtime; these dependency changes currently exist only locally.
