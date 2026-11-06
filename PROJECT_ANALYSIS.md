# Project Analysis — Secure Applicant & New-Hire Onboarding Portal

Status: **Phases A–G delivered (2026-11-05); ready for client UAT.** The client approved the Phase A analysis with two changes: Laravel 12 (§1.1) and no Node.js toolchain (§2). Delivery notes are in §7–§8. Items that still need client decisions are in `OPEN_QUESTIONS.md`.
Date of analysis: 2026-11-03.

Companion documents:
- `docs/architecture.md` — ERD, module map, route map, data flows, trust boundaries.
- `docs/signature-compliance-decisions.md` — signature-mode decision record (initial defaults).
- `OPEN_QUESTIONS.md` — prioritized client decisions. This analysis does not answer them.

---

## 1. Discovered stack and current state

| Item | Finding |
|---|---|
| Repository | `magashish/hrform`, branch `claude/new-session-3hx71o`. **Empty**: no commits, no files, no existing schema/routes/auth/tests to preserve. |
| PHP (container) | 8.3.6 CLI. Extensions present: `pdo_pgsql`, `pdo_mysql`, `pdo_sqlite`, `intl`, `mbstring`, `fileinfo`, `gd`, `zip`, `sodium`, `openssl`, `redis`, `pcntl`. |
| Composer | 2.8.12 |
| Node / npm | Present in the container but **not used**: the project has no Node dependency. |
| Databases available locally | PostgreSQL 16 client; SQLite via PDO. No MySQL client. |
| Production hosting | **Unknown** (see Open Question 12). Nothing is assumed about host, cron path, queue driver or object storage. |

### 1.1 Laravel version (decided)

- The client first said "Laravel 10.5", then approved **Laravel 11.57.1**. On checking, `composer audit` reported **5 security advisories against 11.57.1** (including a *critical* TokenGuard authentication bypass and a *high* CRLF injection in the default `email` validation rule). They are fixed only on 12.x/13.x. Laravel 11 is past upstream security support, so they will never be fixed on 11.
- **Decision (client-approved, 2026-11-03): Laravel 12, `laravel/framework ^12.70.2`** (installed: v12.70.2) on PHP 8.2+ (8.3 in development). `composer audit` is clean at this version.
- Run `composer audit` in CI and before each deployment, and keep to the latest 12.x patch.

---

## 2. Proposed architecture (summary)

A monolithic Laravel application with server-rendered Blade views. It has two separate front doors:

1. **HR area** (`/hr/*`): authenticated HR users (`users` table, `web` guard). Two-factor authentication is mandatory.
2. **Applicant portal** (`/portal/*`): uses no passwords. Access is by invitation code plus email one-time passcode (OTP). Every request is checked against a short-lived applicant session that is bound to the applicant and the invitation.

Key technology choices (deliberately few packages):

| Concern | Choice | Justification |
|---|---|---|
| Frontend | Blade templates + hand-written CSS (`public/css/app.css`) + small vanilla JS files in `public/js` | **No Node.js/npm/Vite** (client requirement): Composer installs everything and there is no asset build step. Server-rendered, accessible and mobile-first. A strict CSP forbids inline scripts/styles. |
| HR auth + 2FA | **Laravel Fortify** (first-party) | Maintained TOTP 2FA, encrypted secrets and recovery codes, password confirmation, rate-limited login. Public registration is disabled. |
| Applicant auth | Custom service + middleware (no package) | The flow is specific to this product: invitation code, email OTP, then a bound session. |
| Queue | Laravel queue, `database` driver by default; Redis optional | No assumption that Redis exists in production. |
| Scheduler | Laravel Scheduler (`schedule:run` every minute via host cron) | Expiry, reminders, retention, ZIP cleanup. |
| Storage | Dedicated **private** disk (`documents`), local or S3-compatible | No public URLs. Every file goes through an authorizing controller. |
| ZIP | PHP `ZipArchive` (ext-zip) | No extra dependency. |
| Tests / quality | PHPUnit (Laravel default), Laravel Pint | Larastan was planned but could not be downloaded in the build environment; it can be added later (`composer require --dev larastan/larastan`). |
| Malware scan | `FileScanner` interface: `NullScanner` (default, records "not scanned") and optional ClamAV (`clamd`) adapter | Configurable. Absence of a scanner is documented as a deployment risk. |
| E-signature | `SignatureProvider` interface: `DisabledSignatureProvider` (default) and `FakeSignatureProvider` (tests only) | No provider is selected, so no credentials are assumed. |

Full module boundaries, ERD and trust boundaries: `docs/architecture.md`.

### 2.1 Applicant access design (recommended)

1. HR creates the applicant (first name, last name, email) and issues an invitation.
2. The system generates a **160-bit random code** (Crockford base32, grouped for manual entry, e.g. `K7QM-…`). Only `HMAC-SHA256(app_key_derived_secret, code)` is stored. A keyed hash allows indexed lookup without storing the code. The raw code exists only in the queued email payload and is never logged or shown to HR again.
3. The email links to `/portal/access#<code>`. The code sits in the URL **fragment**, which browsers never send to the server, so it stays out of access logs, proxies and Referer headers. A few lines of JS move it into a POST form. Without JS the applicant pastes the code manually.
4. After a valid code, the applicant confirms their email address. A 6-digit OTP (hashed, 10-minute expiry, 5 attempts, at most 3 sends per 15 minutes) is sent to the email on file **only if the typed address matches it**. The screen shows the same message either way, which prevents enumeration.
5. On successful OTP the session ID is regenerated. The session stores `applicant_id`, `invitation_id`, `verified_at`. Middleware re-checks on **every request** that the invitation is not revoked or expired, and enforces an idle timeout (default 30 min) and an absolute timeout (default 8 h).
6. Applicants can return while the code is valid; each return requires a fresh OTP. **Reissue** revokes any active invitation and creates a new one. All progress lives on the applicant and their documents, never on the invitation, so it is preserved.
7. Code and OTP endpoints are rate-limited by IP and by invitation. Failures are audited (no raw values) and get identical generic responses.

**Honest limitation:** the invitation and the OTP go to the same mailbox, so email OTP is **not an independent second factor**. It protects against leaked or forwarded links and casual misuse, not against a compromised mailbox. An optional out-of-band factor (for example an HR-communicated PIN, or a date of birth HR entered) can be enabled by configuration if the client wants it (Open Question 7). The prompt also says "single-use" while requiring resume. I read this as: the code is reusable until expiry or revocation, with each use gated by a fresh OTP. The alternative is listed in Open Question 7.

### 2.2 Documents, forms and signatures

- **Templates** (`document_templates`) define: input mode (`online_form`, `wet_signature_upload`, `upload_only`, `acknowledgement`), signature mode (`none`, `wet_signature_upload`, `provider_esign`), required flag, allowed MIME/extension list, max size, supporting-upload requirements, filename pattern override, and an optional blank/print PDF asset.
- **Fields** (`document_fields`) use a closed set of field types (text, textarea, date, email, phone, select, radio, checkbox, number, ssn, attestation checkbox). Their config JSON is validated against a schema per type, so arbitrary rules or HTML are never trusted. Each field type maps to server-side Laravel validation rules.
- **Answers** are stored in `applicant_documents.form_data` with Laravel's `encrypted:array` cast (application-level encryption, AES-256-CBC with MAC). They are never logged and never put in emails. SSN-type fields are masked in HR views until HR explicitly reveals them (each reveal is audited).
- **Wet signature:** the applicant downloads the template's blank PDF, prints, signs, scans/photos, and uploads. Clear instructions are shown. The upload becomes a new `document_version`.
- **Pre-filling official PDFs** (for example the I-9 fillable PDF) from online answers is **out of initial scope**. It needs a PDF library and per-form field mapping, and the official forms change version. See Open Question 2.
- **I-9 and SSA-89 default to `wet_signature_upload`.** Changing any template to `provider_esign` requires a recorded `signature_mode_approvals` entry (who authorized it, reference to the written approval, when, who recorded it). The `provider_esign` mode stays unavailable while the provider is `disabled`.
- The portal does not implement an in-house drawn or typed signature pad.

### 2.3 Status model

Applicant status is **derived** from assigned required documents plus explicit HR lifecycle actions, and is recomputed by `ApplicantProgressService` after every document transition:

`invited → in_progress → (correction_required ⇄ in_progress) → submitted → ready_for_download → transferred → retention_pending → deleted`

Document status uses an explicit transition map in `DocumentStateMachine`. Illegal transitions throw and are never written. Controllers never set `status` directly.

```
not_started → in_progress → awaiting_wet_signature_upload | awaiting_supporting_files → submitted
submitted → under_review → approved → complete
submitted | under_review → correction_required → in_progress → submitted …
```

`ready_for_download` = every required document `approved`/`complete`. `transferred` is set only by an explicit HR action (records who/when) and starts the retention clock.

### 2.4 Retention (proposed, to be confirmed)

- Trigger: HR marks the packet "downloaded and transferred to HR system" → `deletion_due_at = now + RETENTION_DAYS` (default **60, the configured maximum**; values above 60 are rejected unless `RETENTION_ALLOW_OVER_60=true` is set after written approval).
- Separate, configurable **abandoned-application** policy (disabled by default, since it is not approved). Invitation expiry alone never deletes data.
- `legal_hold` flag on an applicant blocks every purge path.
- Command `portal:retention:purge` with `--dry-run` (report only). Automatic purge is **disabled by default** (`RETENTION_AUTO_PURGE=false`) until the client has seen dry-run reports in production.
- Purge order: delete file binaries (versions, supporting files, signature artifacts, temp ZIPs) → verify deletion → delete DB rows (cascading) → write a `retention_events` row that references only a random `applicant_ref` UUID. Audit events reference only that UUID, never names or emails.
- Backups: live purge does not remove data from existing backups, which persist until they expire. Backup retention is a client decision and is not hard-coded.

---

## 3. Assumptions (safe defaults, all configurable)

| # | Assumption | Where configured |
|---|---|---|
| A1 | Laravel ^12.70.2 on PHP 8.2+ (see §1.1). | `composer.json` |
| A2 | PostgreSQL 14+ or MySQL 8 in production. Development uses PostgreSQL. Tests use SQLite in-memory for speed, plus an optional PostgreSQL CI job. | `.env` |
| A3 | All HR users have identical permissions, including settings, template management and HR-user management. No hidden admin tier. Sensitive actions (settings, user management, deletion, SSN reveal) require recent password confirmation and are audited. | Policies |
| A4 | A "technical operator" has **server access only** (deploy, `.env`, artisan). They have no in-app role. The first HR user is created with `php artisan portal:hr-user:create`. | README |
| A5 | A second factor is **mandatory** for HR. **Client decision (2026-11-06): a one-time code emailed at each sign-in** instead of an authenticator app. TOTP remains available. | `HR_2FA_REQUIRED=true`, `HR_2FA_METHOD=email` |
| A6 | Invitation expiry is 7 days. Reminders default to 3 days and 1 day before expiry, plus an "incomplete" reminder at most every 3 days. All configurable, and all off if set to empty. | Settings / `.env` |
| A7 | HR notifications go to all active HR users by default, or to a configured distribution address. | Settings |
| A8 | Filename pattern `{last_name}_{first_name}_{document_type}_{date}`, date format `Ymd`, display timezone configurable (default `UTC` until confirmed). | Settings |
| A9 | Default upload allowlist: PDF, JPEG, PNG (HEIC optional per template, stored as-is, with no conversion). Max 10 MB per file. Per-template overrides. | Templates / `.env` |
| A10 | Files are protected by storage-level encryption (host/bucket). App-level file encryption is an optional toggle (`DOCUMENT_APP_ENCRYPTION`), off by default pending a key-management decision. | `.env` |
| A11 | Per-document HR approval (approve / request correction). A packet is `ready_for_download` when all required documents are approved. | — |
| A12 | Superseded document versions are kept until purge so HR can see correction history. Configurable to delete superseded binaries immediately. | `.env` |
| A13 | No HRIS integration and no e-sign provider in scope. | — |
| A14 | IP address and user agent are recorded in audit and consent records as security metadata. They can be turned off if the client's privacy policy objects. | `.env` |
| A15 | Audit logs are kept for 1 year by default, in pseudonymous form only (no names, emails or content). Pending confirmation. | `.env` |

---

## 4. Risks

| Risk | Severity | Mitigation / owner |
|---|---|---|
| Framework vulnerabilities | Medium | Resolved for now by moving to Laravel 12 (Open Question 0). Keep patching; run `composer audit` in CI. |
| I-9 legal requirements (electronic signature, and the fact that physical document examination in Section 2 is the employer's responsibility outside this portal) | High | Wet-sign default. Approval record required before e-sign. The portal handles applicant-side completion only. Legal sign-off is the client's responsibility. |
| Collection of SSNs and identity documents | High | App-level encryption of form data, private storage, masking, audited reveal, minimal retention, no emailing of content. |
| Email-only identity verification | Medium | Documented limitation. Optional out-of-band factor. Short sessions. |
| No malware scanner | Medium | `FileScanner` interface. Strict MIME/extension/magic-byte validation. Files served with `Content-Disposition: attachment` and `nosniff`. Documented as a deployment risk. |
| Backups retaining purged data | Medium | Documented. Client must set backup retention consistent with policy. |
| Mis-sent invitation (wrong email entered by HR) | Medium | Email shown for confirmation on create. HR can revoke immediately. Consent gate before any form. |
| Notification spam to HR (≈5 users × many events) | Low | Configurable recipients. Optional digest (Open Question 8). |
| Large ZIP generation timeouts | Low | Queued job, size limits, temp cleanup. |
| Scope creep from "online completion where possible" for official PDFs | Medium | Pre-fill deferred. Per-form decision. |

---

## 5. Phased implementation plan

Each phase ends with: tests passing (`php artisan test`), Pint clean, a list of changed files, outstanding issues, and a commit/push to the working branch.

### Phase A — Analysis and design (this phase)
`PROJECT_ANALYSIS.md`, `docs/architecture.md`, `docs/signature-compliance-decisions.md`, `OPEN_QUESTIONS.md`, `README.md` (stub). **Stop for review.**

### Phase B — Foundation
- Scaffold Laravel ^12.70.2 (no Node toolchain), configure private `documents` disk, security headers middleware, session hardening, production safety checks (`APP_DEBUG` off, HTTPS forced).
- Fortify: login, mandatory TOTP 2FA enrollment, recovery codes, password confirmation; no registration.
- `portal:hr-user:create` command; dev-only seeder that reads credentials from env (refuses to run in production).
- Audit foundation: `audit_events`, `AuditLogger` service with **allowlisted** metadata keys per event, append-only model guards (plus a PostgreSQL trigger when that driver is used). **Delivered** (see §7).
- Policies skeleton, `EnsureTwoFactorEnabled` middleware.
- Tests: login, 2FA gate, unauthenticated denial, audit sanitization.

### Phase C — Applicants, invitations, consent
- Applicant CRUD (Form Requests, policy, search/pagination).
- `InvitationService`: issue / reissue / revoke, HMAC hashing, 7-day expiry, queued invitation mail, delivery logging.
- Applicant access flow: code → email OTP → bound session middleware; rate limiters; generic errors; failed-attempt auditing.
- Consent versions (HR-managed; placeholder text clearly marked "DRAFT — client wording pending"), acknowledgement gate middleware.
- Tests: hashing, expiry, revocation, reissue preserves progress, rate limiting, generic responses, cross-applicant isolation, consent gate.

### Phase D — Templates, forms, uploads
- Template and field management with config validation. Seed I-9 and SSA-89 templates as `wet_signature_upload` with **no** official PDF bundled (HR uploads the current official form).
- Assignment of templates to applicants (defaults plus per-applicant additions).
- Dynamic form renderer and server validation, draft save/resume.
- `UploadService`: MIME sniffing (`finfo`) + extension + size + allowlist; reject scripts/executables/macros/polyglot signatures; randomized storage paths; SHA-256; scanner hook; versioning.
- `FilenameGenerator`: pattern tokens, Unicode normalization (NFKC → ASCII transliteration via intl), separator/unsafe-character stripping, length cap, collision suffixes.
- Supporting files linked to requirement keys. `DocumentStateMachine`, `ApplicantProgressService`.
- Tests: required/optional rules, wet-sign flow, supporting association, rejections, naming, save/resume, ID tampering.

### Phase E — HR review and export
- Dashboard (counts, filters), applicant detail checklist, authorized inline preview/download.
- Approve / request correction (message to the applicant; email contains no content), applicant replacement creates a new version.
- `BuildApplicantZip` queued job: folder structure, safe names, path-traversal-proof entry names, only the applicant's own files; authorized download; temp expiry.
- Mark transferred (who/when) → retention clock.
- Tests: download authorization, ZIP contents/names, correction/versioning, audit events.

### Phase F — Notifications and lifecycle
- Event notifications (submitted, uploaded/replaced, completed, correction requested), editable mail templates (subject/body with a safe placeholder set), queued delivery log.
- Scheduler: invitation expiry marking, reminders (idempotent; tracked in `reminder_dispatches`), retention dry-run/purge, legal hold, ZIP cleanup, optional health check.
- `docs/retention-and-backup.md`.
- Tests: reminder scheduling, retention dry-run/purge/legal hold, ZIP cleanup.

### Phase G — Hardening and handover
- Full authorization test matrix, security review (`/security-review`), upload fuzz cases, `composer audit`, performance checks with seeded volume.
- E-sign adapter contract tests using a fake provider, and the webhook signature/idempotency skeleton (disabled).
- `docs/security-and-privacy.md`, `docs/deployment.md`, complete README, restore test plan, UAT checklist for the client's sample forms.

---

## 6. What needs client input before or during build

See `OPEN_QUESTIONS.md`. Items that **block specific phases** (other work proceeds with configurable defaults):
- Phase B: ~~Open Question 0~~ (resolved: Laravel 12), 12 (hosting/DB).
- Phase C: Open Questions 3 (consent wording; a placeholder is used meanwhile), 7 (identity verification method).
- Phase D: Open Questions 1, 2, 6 (forms, fields, naming). The engine is built generically; real templates are loaded once supplied.
- Phase F: Open Questions 8, 10, 11 (reminders, retention trigger, backups).
- Any `provider_esign` use: Open Questions 4, 14.

---

## 7. Phase B delivery notes (2026-11-03)

Delivered:
- Laravel 12.70.2 + Fortify 1.41 scaffold. The Node/Vite/Tailwind files were removed; styling is `public/css/app.css`.
- HR authentication under `/hr`: login, password reset, password change, password confirmation for sensitive actions. **Public registration disabled**; passkeys disabled.
- **Mandatory TOTP 2FA**: users without confirmed 2FA are redirected to enrolment from every HR page. Disabling 2FA is blocked while `HR_2FA_REQUIRED=true`. Recovery codes are shown once, after confirmation or regeneration.
- Disabled HR accounts cannot log in (same generic error as a bad password). Active sessions end on the next request, and database sessions are deleted.
- The password-reset form gives an identical response for known and unknown emails and is rate-limited per IP. The reset URL carries no email address.
- HR user management, available to every HR user (equal permissions): list, create (the new user is emailed a set-password link; HR never sets another user's password), disable/enable (cannot disable yourself). Writes require password re-confirmation.
- `php artisan portal:hr-user:create` for the technical operator. The password is prompted, never passed as an argument.
- Development seeder (`DEV_HR_EMAIL`/`DEV_HR_PASSWORD`) refuses to run in production.
- Audit foundation: declared-event catalogue (`config/audit.php`), per-event metadata allowlist, scalar-only values, truncation, append-only model, PostgreSQL trigger (updates always blocked, deletes only with `SET LOCAL app.audit_purge = 'on'`). Covers login, failure, lockout, logout, 2FA lifecycle, password reset/change and HR user changes.
- Security baseline: strict CSP with no inline script/style, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, `Cache-Control: no-store`, optional HSTS. Sessions are encrypted, HttpOnly, SameSite=Lax, secure in production, with a 30-minute idle timeout. HTTPS is forced and debug is forced off in production. Sensitive inputs are never flashed back to the session.
- Private `documents` and `exports` disks (not web-served, 0600/0700 permissions, S3-ready).

Tests: 47 (all pass on PostgreSQL 16; on SQLite 44 pass and 3 PostgreSQL-trigger tests are skipped).

Outstanding / carried forward: Larastan not installed (its download was blocked in the build environment).

---

## 8. Phases C–G delivery notes (2026-11-05)

**C: Applicants, invitations, consent.**
- Applicant CRUD with search and filters.
- Invitation issue, reissue and revoke. Codes are 160-bit Crockford base32, stored only as an HMAC, valid 7 days, and emailed through an encrypted queued mail. The link carries the code in the URL fragment.
- Sign-in is code → email match → OTP → a session bound to the applicant and invitation, with idle and absolute timeouts.
- Disclosure versions are immutable. The active version must be acknowledged, and a new version requires acknowledging again.

**D: Templates, forms, uploads.**
- HR-managed templates. Input modes: online form, upload, acknowledgement. Signature modes: none or wet signature; provider e-sign is blocked until a provider is configured and an approval is recorded.
- Closed set of field types with validated configuration. Answers are encrypted at rest.
- Uploads go through an allowlist, content-type sniffing and active-content rejection, then a scanner hook. Files are stored privately under random names, with SHA-256 and versioning.
- Supporting-file requirements per template. Filename generator with transliteration and collision handling.
- Document state machine; applicant status is derived from document statuses.

**E: Review and export.**
- Dashboard counts and filters. Applicant detail page with checklist, invitation history, consent record and activity timeline.
- Approve or request correction. The correction message is shown in the portal only; the email says only that a correction is needed.
- SSNs are masked, with an audited reveal that requires the password again.
- Authorized download and preview of files. Individual file deletion.
- Queued ZIP export: folder per document, generated names, form-answer HTML, SHA-256 manifest; downloadable only by the requester and expiring after 60 minutes.
- Mark transferred: records who and when, starts retention and revokes access.

**F: Notifications and lifecycle.**
- HR alerts (submitted, uploaded/replaced, packet completed, delivery failed, purge failed) go to configurable recipients and contain no personal data.
- Applicant correction and reminder emails with editable subject and opening paragraph.
- Idempotent expiry and incomplete-onboarding reminders.
- Retention dry run and purge with legal hold; manual deletion.
- Export and OTP cleanup; audit pruning.
- Settings page and System health page. Scheduler defined in `routes/console.php`.

**G: Hardening and handover.**
- `SignatureProvider` interface with disabled (default) and fake (tests only) providers; signature-transaction tables; HMAC-verified, idempotent webhook.
- Form-friendly password re-confirmation.
- Strict CSP verified on every page by tests.
- PostgreSQL-safe idempotency (`insertOrIgnore`).
- End-to-end browser run: HR login with 2FA → create applicant → applicant signs in on a phone → consent → wet-signature upload (including rejection of a disguised PHP file) → submit → HR approve → ZIP download.
- Documentation: `docs/security-and-privacy.md`, `docs/retention-and-backup.md`, `docs/deployment.md`, and a complete README.

**Tests:** 123 automated tests (PostgreSQL: all pass; SQLite: 120 pass and 3 PostgreSQL-trigger tests are skipped). They cover the list in the master prompt §14.

**Known limitations / not built (by design or pending decisions):**
- No pre-filling of official PDFs from online answers (OPEN_QUESTIONS #2).
- No real e-signature provider adapter (#4, #14).
- Malware scanning is off unless ClamAV is configured (#13).
- Email OTP is not an independent second factor (#7).
- Final disclosure wording, forms, naming pattern, reminder timing and retention trigger use interim defaults until confirmed.

---

## 9. Change: HR second factor by email (2026-11-06)

The client does not want HR staff to use an authenticator app.
- `HR_2FA_METHOD=email` is now the default. After the password, a 6-digit code is emailed. It is HMAC-stored, valid 10 minutes, allows 5 attempts and at most 3 sends per 15 minutes, and is sent synchronously so sign-in does not depend on the queue worker.
- The code step is tied to the user and the session. Fortify's authenticator challenge is skipped in this mode.
- `HR_2FA_METHOD=totp` restores authenticator apps.
- **Risk:** this is weaker than TOTP if an HR mailbox is compromised. The client should require MFA on HR mailboxes (recorded in docs/security-and-privacy.md).
- Tests: 7 new tests; full suite 130 passing on PostgreSQL.
