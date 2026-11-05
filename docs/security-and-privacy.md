# Security and Privacy

This document describes the controls implemented in the portal and the operational responsibilities that remain with the client and the hosting operator. The portal applies layered controls; it is **not** claimed to be "completely secure" or legally compliant on its own.

## 1. Data held

| Data | Where | Protection |
|---|---|---|
| Applicant name, email | `applicants` | Database access controls; deleted at purge |
| Form answers (may include SSN, date of birth, addresses) | `applicant_documents.form_data` | **Application-level encryption** (Laravel `encrypted:array`, AES-256-CBC + MAC, key = `APP_KEY`); never logged, never emailed; SSN/sensitive fields masked in HR views |
| Uploaded documents and supporting evidence | Private `documents` disk, random `.bin` names under `applicants/{applicant_ref}/` | Not web-served; every download goes through an authorizing controller; SHA-256 recorded; storage-level encryption is the host's responsibility (see §6) |
| Invitation codes | `applicant_invitations.token_hash` | Only an HMAC-SHA256 (key derived from `APP_KEY`) is stored; the raw code exists only in the encrypted queued email job |
| Email one-time codes | `applicant_otps.code_hash` | HMAC only; 10-minute expiry; 5 attempts; rows deleted after a day |
| HR passwords, 2FA secrets, recovery codes | `users` | bcrypt; 2FA secret and recovery codes encrypted |
| Audit trail | `audit_events` | Pseudonymous (`applicant_ref` UUID), allowlisted metadata only; append-only |
| Temporary ZIP exports | Private `exports` disk | Requester-only download; expire after `EXPORT_TTL_MINUTES` (default 60), then deleted by the scheduler |

## 2. Access control

**HR users** (`/hr`)
- Email + password (minimum 12 characters, mixed case, number and symbol; optional breached-password check) and **mandatory TOTP 2FA**. HR pages stay locked until 2FA is confirmed, and 2FA cannot be switched off while `HR_2FA_REQUIRED=true`.
- Login is rate-limited (5/min per account+IP, 20/min per IP). The password-reset form gives an identical response for known and unknown addresses and is rate-limited per IP.
- 30-minute idle session timeout. The cookie expires when the browser closes.
- Sensitive actions require the password again within 15 minutes: settings, templates, HR users, SSN reveal, file deletion, legal hold, packet deletion.
- Disabled accounts cannot log in, and their open sessions end on the next request.
- All HR users have equal permissions (client requirement). Policies (`ApplicantPolicy`, `HrUserPolicy`) still guard every action so that future restrictions are a policy change. The only built-in restriction is that a user cannot disable their own account.

**Applicants** (`/portal`)
- No passwords. Sign-in is invitation code → confirm the email on file → 6-digit code emailed to that address.
- Invitation links carry the code in the URL **fragment** (`#…`), which browsers never send to the server, so it never appears in server logs, proxies or Referer headers. A small script moves it into a POST form and removes it from the address bar.
- Every failure (unknown, expired or revoked code; wrong email; wrong or expired OTP) gives the same generic message. The reason is recorded only in the audit log.
- Every request re-checks the session: the invitation must still belong to the applicant and must not be revoked or expired. There is a 30-minute idle timeout and an 8-hour absolute timeout.
- Applicant pages never accept an applicant ID from the URL. Documents are looked up only within the signed-in applicant's own documents, so another applicant's document ID returns **404**.

**Known limitation:** the invitation and the one-time code go to the same mailbox, so the email code is not an independent second factor. It protects against leaked or forwarded links, not against a compromised mailbox (OPEN_QUESTIONS #7).

## 3. Uploads

- Extension must be in both the global allowlist (`pdf, jpg, jpeg, png, heic`) and the template's list.
- Content type is detected from the file bytes (`finfo`), not from the browser, and must match the extension.
- Rejected outright: PHP/HTML/script markers in images; PDFs containing `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile`, `/RichMedia` or `/XFA`. Images must decode.
- Per-template size limits, capped by `UPLOAD_HARD_MAX_KB`.
- Stored under random names with no extension, outside the web root. Original filenames are kept as metadata only. Client-facing names come from the approved naming pattern.
- Malware scanning: `MALWARE_SCANNER=clamav` streams each file to `clamd`. Infected files are rejected. With `MALWARE_SCANNER_FAIL_CLOSED=true` (the default), uploads are refused if the scanner is unreachable. **The default is `none`**: files are recorded as `not_scanned`, and the HR System page flags this. This is a deployment risk the client must accept or resolve (OPEN_QUESTIONS #13).
- Downloads are sent as `application/octet-stream` attachments with `nosniff`. Inline previews (PDF and images only) carry a sandboxed CSP.

## 4. Web security baseline

- Content-Security-Policy with no inline scripts or styles (`script-src 'self'; style-src 'self'; frame-ancestors 'none'; form-action 'self'` …), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cache-Control: no-store`. HSTS is available via `SECURITY_HSTS=true`.
- CSRF protection on all forms. The e-signature webhook is exempt and verified by HMAC signature instead.
- Sessions are encrypted, HttpOnly and SameSite=Lax, and Secure in production. Session IDs are regenerated at every sign-in step.
- HTTPS is forced and `APP_DEBUG` is forced off when `APP_ENV=production`.
- Validation runs through Form Requests or inline validators. Models use `$fillable`/`$guarded`. Eloquent strict mode is on outside production.
- Passwords, codes and OTPs are never flashed back into forms after validation errors.

## 5. Audit and logging

- Every event name and its permitted metadata keys are declared in `config/audit.php`. Undeclared events throw. Unlisted keys, arrays and objects are dropped, and strings are truncated.
- Covered: HR login, failures, lockouts and logout; 2FA lifecycle; password changes and resets; HR user changes; applicant creation and updates (field names only); invitations (issue, revoke, delivery); applicant sign-in steps; consent; template and settings changes; document assignment, draft, upload, submission, approval and correction (scope only, never the message); views, downloads and previews; sensitive-field reveals; exports; transfer; legal hold; reminders; retention runs and purges.
- Audit rows never contain names, emails, form answers, codes or file contents. IP address and user agent are recorded by default (`AUDIT_RECORD_IP`, `AUDIT_RECORD_USER_AGENT`).
- Append-only: the model refuses updates and deletes. On PostgreSQL a trigger blocks `UPDATE` always and blocks `DELETE` except inside the audit-pruning job (`SET LOCAL app.audit_purge = 'on'`).
- The application does not log request bodies. **Do not use `MAIL_MAILER=log` in production**: it writes full emails, including links and codes, to the log file.

## 6. Operator responsibilities (not enforceable by the application)

1. TLS certificate and HTTPS-only access. Enable `SECURITY_HSTS` once HTTPS is permanent.
2. Encryption at rest for the database volume and the document storage (disk encryption or S3 SSE-KMS), with documented key management.
3. Keep `APP_KEY` secret and backed up separately. Losing it makes encrypted form data and 2FA secrets unreadable, and changing it invalidates all invitation codes.
4. Restrict filesystem permissions: the web server user owns `storage/`, and `storage/app/private` must not be web-accessible.
5. Run a malware scanner, or formally accept the risk.
6. Patch PHP, the OS and dependencies. Run `composer audit` before each deployment.
7. Back up and restore, with a backup retention period aligned with the deletion policy (see `retention-and-backup.md`).
8. Configure the mail provider with SPF, DKIM and DMARC so invitations are not treated as phishing.
9. Monitor the HR **System** page: scheduler heartbeat, failed jobs, scanner status.

## 7. Legal / compliance boundary

The portal implements technical controls. It does not determine whether a process satisfies I-9, SSA, state privacy or retention law. Signature decisions are recorded in `signature-compliance-decisions.md`, and items needing client or legal input are in `OPEN_QUESTIONS.md`.
