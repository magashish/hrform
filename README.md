# HR Onboarding Portal

A secure, temporary portal where HR invites applicants and new hires to complete onboarding paperwork. Applicants verify their invitation, acknowledge a disclosure, fill in online forms, print and hand-sign documents, and upload signed copies and supporting evidence. HR reviews each document, requests corrections, downloads files individually or as a ZIP, marks the packet as transferred to the employer's HR system, and the portal then deletes it on a retention schedule.

**Status:** feature-complete for client UAT. Several business decisions still use interim defaults; see [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md).

## Documentation

| Document | Contents |
|---|---|
| [`PROJECT_ANALYSIS.md`](PROJECT_ANALYSIS.md) | Stack, architecture, assumptions, risks, phase plan, delivery notes |
| [`docs/architecture.md`](docs/architecture.md) | ERD, modules, routes, data flows, trust boundaries |
| [`docs/security-and-privacy.md`](docs/security-and-privacy.md) | Controls implemented and operator responsibilities |
| [`docs/signature-compliance-decisions.md`](docs/signature-compliance-decisions.md) | Signature mode per form (I-9 / SSA-89 = wet signature) |
| [`docs/retention-and-backup.md`](docs/retention-and-backup.md) | Retention, legal hold, purge, backup caveats, restore test |
| [`docs/deployment.md`](docs/deployment.md) | Server setup, cron and queue, nginx, go-live checklist |
| [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md) | Decisions needed from the client |

## Stack

- PHP 8.2+ (8.3 recommended), **Laravel 12**, Laravel Fortify (HR login); second factor = **one-time code by email** (default) or authenticator app
- PostgreSQL 14+ (recommended) or MySQL 8. SQLite is fine for local trials.
- **No Node.js.** Blade templates, plain CSS (`public/css/app.css`) and a small vanilla JS file (`public/js/app.js`). There is no build step.

## How it works

**HR** (`/hr`): sign in with password, then enter the 6-digit code emailed to you, then:

1. **Disclosure**: create and activate the consent text applicants must accept.
2. **Documents**: set up templates (online form, upload, or acknowledgement; wet signature or none; accepted file types; supporting files; printable blank PDF). I-9 and SSA-89 are pre-configured as wet signature.
3. **Applicants → Add applicant**: name, email and documents. A 7-day invitation is emailed automatically.
4. Track progress, review each document (approve or request a correction), download files or a ZIP, then **Mark as transferred**. The packet is deleted after the retention period (default 60 days).
5. **Send new invitation** at any time if the applicant needs more time; their progress is kept.

**Applicants** (`/portal`): open the email link → confirm their email address → enter the 6-digit code → accept the disclosure → complete each document (forms save as drafts; wet-signature forms are downloaded, printed, signed by hand and uploaded) → submit.

## Local setup

```bash
composer install
```

```bash
cp .env.example .env && php artisan key:generate
```

For a quick SQLite trial, set `DB_CONNECTION=sqlite` in `.env`, comment out the other `DB_*` lines, and set `MAIL_MAILER=log`. That mailer writes emails to `storage/logs/laravel.log`, which is how you can see invitation links and codes locally. **Never use it in production.**

```bash
touch database/database.sqlite && php artisan migrate
```

Seed development data. This creates the I-9 and SSA-89 templates, a DRAFT disclosure and an HR user; it refuses to run in production:

```bash
DEV_HR_EMAIL=you@example.com DEV_HR_PASSWORD='Choose-A-Str0ng-Pass!' php artisan db:seed
```

Or create an HR user interactively:

```bash
php artisan portal:hr-user:create you@example.com --name="Your Name"
```

Run the app, a queue worker and the scheduler, each in its own terminal:

```bash
php artisan serve
```

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

Open `http://localhost:8000/hr/login`. After your password you'll be asked for a 6-digit code sent to your email (with `MAIL_MAILER=log`, find it in `storage/logs/laravel.log`).

## Key environment variables

| Variable | Default | Purpose |
|---|---|---|
| `HR_2FA_REQUIRED` | `true` | Second factor required for HR |
| `HR_2FA_METHOD` | `email` | `email` = one-time code emailed at each sign-in (10 min, 5 attempts, 3 sends per 15 min); `totp` = authenticator app |
| `HR_PASSWORD_MIN_LENGTH` | `12` | HR password length |
| `AUTH_PASSWORD_TIMEOUT` | `900` | Seconds before sensitive actions re-ask for the password |
| `SESSION_LIFETIME` | `30` | HR idle timeout (minutes) |
| `APPLICANT_IDLE_TIMEOUT_MINUTES` / `APPLICANT_ABSOLUTE_TIMEOUT_MINUTES` | `30` / `480` | Applicant session limits |
| `INVITATION_EXPIRY_DAYS` | `7` | Invitation lifetime |
| `APPLICANT_OTP_TTL_MINUTES` / `APPLICANT_OTP_MAX_ATTEMPTS` | `10` / `5` | Email code limits |
| `APP_DISPLAY_TIMEZONE` | `UTC` | Timezone for displayed dates and filenames |
| `DOCUMENTS_DRIVER` | `local` | `local` (outside the web root) or `s3` (private, encrypted bucket; `DOCUMENTS_S3_*`) |
| `UPLOAD_DEFAULT_MAX_KB` / `UPLOAD_HARD_MAX_KB` | `10240` / `25600` | Upload size limits |
| `MALWARE_SCANNER` | `none` | `clamav` to scan uploads (`CLAMAV_SOCKET` or `CLAMAV_HOST`/`PORT`) |
| `RETENTION_DAYS` | `60` | Days after transfer before purge (maximum 60 unless `RETENTION_ALLOW_OVER_MAX`) |
| `RETENTION_AUTO_PURGE` | `false` | Off = scheduler only writes dry-run reports |
| `EXPORT_TTL_MINUTES` | `60` | ZIP export lifetime |
| `AUDIT_RETENTION_DAYS` | `365` | Pseudonymous audit-log retention |
| `ESIGN_PROVIDER` | `disabled` | No e-signature provider is selected |
| `TRUSTED_PROXIES`, `SECURITY_HSTS` | empty, `false` | Reverse proxy and HSTS |

Reminder timing, notification recipients, filename pattern, retention days and email wording are also editable by HR under **Settings**.

## Scheduled and queued work

Cron runs `php artisan schedule:run` every minute (see [`routes/console.php`](routes/console.php)). The queue worker sends all email and builds ZIP exports.

| Command | Schedule | Purpose |
|---|---|---|
| `portal:reminders:send` | hourly | Invitation-expiry and incomplete-onboarding reminders |
| `portal:exports:cleanup` | every 15 min | Delete expired ZIPs and stale email codes |
| `portal:retention:purge [--dry-run] [--force]` | daily 02:15 | Retention report or purge (respects legal hold) |
| `portal:audit:prune` | daily 02:45 | Remove audit events past retention |
| `portal:heartbeat` | every 5 min | Scheduler health for the System page |
| `portal:hr-user:create` | manual | Create an HR account |

## Testing

```bash
php artisan test
```

```bash
vendor/bin/pint --test
```

```bash
composer audit
```

The PostgreSQL-only tests (audit-log trigger) are skipped on SQLite. To run everything against PostgreSQL:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=onboarding_test DB_USERNAME=onboarding DB_PASSWORD=secret php artisan test
```

## Deployment, backup and retention

See [`docs/deployment.md`](docs/deployment.md) and [`docs/retention-and-backup.md`](docs/retention-and-backup.md). In short:
- Production runs with `APP_ENV=production`, HTTPS only, a queue worker under a supervisor, and cron for the scheduler.
- Documents are stored on encrypted private storage.
- Backup retention must match the deletion policy: deleting a packet does not remove it from existing backups.
