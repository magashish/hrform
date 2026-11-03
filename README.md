# HR Onboarding Portal

A secure, temporary portal where HR invites applicants and new hires to complete onboarding paperwork. HR then reviews, downloads and transfers the documents to the employer's existing HR system, after which the portal deletes them.

**Status:** Phase B (foundation) complete: HR login with mandatory 2FA, HR user management, audit log and security baseline. Applicant features arrive in Phases C–F (see [`PROJECT_ANALYSIS.md`](PROJECT_ANALYSIS.md)).

Design documents:
- [`PROJECT_ANALYSIS.md`](PROJECT_ANALYSIS.md): stack, architecture, assumptions, risks, phased plan, delivery notes
- [`docs/architecture.md`](docs/architecture.md): ERD, modules, routes, data flows, trust boundaries
- [`docs/signature-compliance-decisions.md`](docs/signature-compliance-decisions.md): signature-mode decisions
- [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md): client decisions needed

## Stack

- PHP 8.2+ (8.3 recommended), Laravel 12, Laravel Fortify (HR login + TOTP 2FA)
- PostgreSQL 14+ (recommended) or MySQL 8. SQLite is fine for local trials.
- **No Node.js required.** Blade templates, plain CSS in `public/css`, small vanilla JS in `public/js`. There is no asset build step.
- Required PHP extensions: `pdo_pgsql` or `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `intl`, `zip`, `gd`.

## Local setup

```bash
composer install
```

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

Edit `.env` for your database. For a quick local trial with SQLite set `DB_CONNECTION=sqlite`, comment out the other `DB_*` lines and create the file:

```bash
touch database/database.sqlite
```

```bash
php artisan migrate
```

Create the first HR user. You'll be prompted for a password, which must be at least 12 characters with mixed case, a number and a symbol:

```bash
php artisan portal:hr-user:create you@example.com --name="Your Name"
```

Alternatively, for local development only, set `DEV_HR_EMAIL` and `DEV_HR_PASSWORD` in `.env` and run `php artisan db:seed`. The seeder refuses to run in production.

Start the app and a queue worker:

```bash
php artisan serve
```

```bash
php artisan queue:work
```

Open `http://localhost:8000/hr/login`. At first sign-in you'll be asked to confirm your password and enrol an authenticator app (TOTP). HR pages stay locked until 2FA is confirmed.

For local email testing set `MAIL_MAILER=log`. Note that this writes full emails, including password-reset and invitation links, to `storage/logs`. **Never use the `log` mailer in production.**

## Key environment variables

| Variable | Default | Purpose |
|---|---|---|
| `HR_2FA_REQUIRED` | `true` | Mandatory TOTP 2FA for HR users |
| `HR_PASSWORD_MIN_LENGTH` | `12` | HR password minimum length |
| `HR_PASSWORD_CHECK_UNCOMPROMISED` | `false` | Check passwords against Have I Been Pwned (needs outbound HTTPS) |
| `AUTH_PASSWORD_TIMEOUT` | `900` | Seconds before sensitive actions re-ask for the password |
| `SESSION_LIFETIME` | `30` | Idle session timeout (minutes) |
| `SESSION_SECURE_COOKIE` | `true` in production | HTTPS-only session cookie |
| `TRUSTED_PROXIES` | empty | Proxy IPs/CIDRs when TLS is terminated upstream |
| `SECURITY_HSTS` | `false` | Send HSTS (enable once HTTPS is permanent) |
| `APP_DISPLAY_TIMEZONE` | `UTC` | Timezone for displayed dates (storage is UTC) |
| `AUDIT_RECORD_IP` / `AUDIT_RECORD_USER_AGENT` | `true` | Security metadata on audit events |
| `DOCUMENTS_DRIVER` | `local` | `local` (outside the web root) or `s3` (private, encrypted bucket) |
| `INVITATION_EXPIRY_DAYS` | `7` | Applicant invitation lifetime (Phase C) |

## Testing

```bash
php artisan test
```

```bash
vendor/bin/pint --test
```

The PostgreSQL-only audit trigger tests are skipped on SQLite. To run them against PostgreSQL:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=onboarding_test DB_USERNAME=onboarding DB_PASSWORD=secret php artisan test
```

Check dependencies for known vulnerabilities before each deployment:

```bash
composer audit
```

## Production notes (summary; full guide in Phase G)

- Set `APP_ENV=production` and `APP_DEBUG=false`. Debug is also forced off in production as a safety net.
- Serve over HTTPS only. Point the web server's document root at `public/`.
- Run a queue worker under a process supervisor (`php artisan queue:work`) and the scheduler from cron, once a minute: `php artisan schedule:run`.
- Document storage must be private: either the default `storage/app/private/documents` (outside the web root, restrictive permissions), or a private S3-compatible bucket with server-side encryption.
- Backup, retention and deployment guides will be added in Phases F–G.
