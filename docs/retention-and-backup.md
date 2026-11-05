# Retention, Deletion and Backup

The portal is a **temporary** holding area. The employer's existing HR system is the permanent record.

## 1. Retention policy (implemented; trigger and duration pending confirmation, OPEN_QUESTIONS #10)

| Item | Default | Setting |
|---|---|---|
| Retention trigger | HR clicks **Mark as transferred** after verifying the ZIP or files are in the HR system | Workflow |
| Retention period after transfer | 60 days (maximum allowed without approval) | HR Settings → Retention; `RETENTION_DAYS` |
| Exceeding 60 days | Rejected unless `RETENTION_ALLOW_OVER_MAX=true` is set after written approval | `.env` |
| Automatic purge | **Off**: the scheduler records dry-run reports only | `RETENTION_AUTO_PURGE` |
| Abandoned / incomplete applications | **Not purged** (an expired invitation never deletes data) | `RETENTION_ABANDONED_DAYS` (0 = off) |
| Superseded file versions | Kept until the packet is purged | `DELETE_SUPERSEDED_FILES` |
| Temporary ZIP exports | Deleted 60 minutes after creation | `EXPORT_TTL_MINUTES` |
| Email verification codes | Deleted after 1 day | — |
| Audit events (pseudonymous) | 365 days | `AUDIT_RETENTION_DAYS` |

Marking a packet as transferred also revokes any active invitation, so the applicant can no longer sign in.

## 2. Legal hold

HR can place a legal hold on an applicant, with a mandatory reason; the action is audited and requires a password re-confirmation. While a hold is set, scheduled and manual purges are refused, the HR "Delete" option is hidden, and the System page counts held packets that are past their deletion date.

## 3. Purge procedure (`App\Services\Retention\RetentionService`)

1. Re-load the applicant and refuse if it is under legal hold.
2. Delete every file binary (all document versions, supporting files and ZIP exports) and verify each is gone. If any deletion cannot be verified, **abort** for that applicant, record `retention_events.action = failed`, and notify HR.
3. In a transaction: delete the applicant row. Foreign-key cascades remove invitations, OTPs, consents, documents, versions, supporting-file records, corrections, exports and reminder records.
4. Record `retention_events` (`purged`, the trigger, the number of files deleted) and an `audit_events` row. Both reference only the random `applicant_ref`; no name, email or content survives.

Commands:

```bash
php artisan portal:retention:purge --dry-run
```

```bash
php artisan portal:retention:purge --force
```

The scheduler runs `portal:retention:purge` daily at 02:15 (server time). It only purges when `RETENTION_AUTO_PURGE=true`; otherwise it writes dry-run report rows, which are visible on the HR **System** page.

**Recommended go-live sequence:** keep auto-purge off, review dry-run reports for a few weeks, obtain client sign-off, then set `RETENTION_AUTO_PURGE=true`.

Manual deletion: HR → applicant → *Delete this applicant permanently*. This requires typing `DELETE` and a password re-confirmation, follows the same procedure with `trigger = manual`, and is blocked by legal hold.

## 4. Backups: important caveat

**Deleting a packet from the live system does not remove it from existing backups.** Copies remain until those backups expire. Make the backup retention period consistent with the deletion policy and any legal obligations; it is a client decision and is not hard-coded (OPEN_QUESTIONS #11).

Recommended baseline (adjust to the client's policy):

| What | How | Suggested retention |
|---|---|---|
| Database | Encrypted daily dump (`pg_dump -Fc` / `mysqldump`) to separate storage | Short, e.g. 14–30 days |
| Documents disk | Encrypted, versioned bucket or incremental snapshots of `storage/app/private/documents` | Same as database, so a restore is consistent |
| `APP_KEY` | Stored separately in a secrets manager | For as long as any backup that depends on it exists |

If the client wants a deletion to be complete within N days, the backup retention must be N days or less.

Do not back up `storage/app/private/exports`; these are temporary ZIP files.

## 5. Restore test plan (run before go-live and at least quarterly)

1. Restore the latest database dump and documents snapshot into an **isolated** environment with the production `APP_KEY`.
2. Run `php artisan migrate:status`. All migrations should be present.
3. Sign in as an HR test account, open a recent applicant, preview a document and reveal a test SSN field. This proves `APP_KEY` and file integrity; compare SHA-256 values with the database.
4. Run `php artisan portal:retention:purge --dry-run` and confirm that restored packets past their deletion date are reported. Purge them in the restored environment if it will be kept.
5. Record the date, the backup used, the result and the time taken. Then destroy the isolated environment.

## 6. Audit-log pruning

`portal:audit:prune` runs daily and deletes audit events older than `AUDIT_RETENTION_DAYS`. On PostgreSQL it is the only process allowed to delete from `audit_events`, which it does through a transaction-local flag that the database trigger checks.
