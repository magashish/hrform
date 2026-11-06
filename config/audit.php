<?php

/*
|--------------------------------------------------------------------------
| Audit event catalogue
|--------------------------------------------------------------------------
|
| Every audit event must be declared here with the metadata keys it may
| carry. Keys not listed are dropped by AuditLogger. Never add keys that
| would hold names, email addresses, form answers, document contents,
| access codes, OTPs, passwords or tokens.
|
*/

return [

    'events' => [
        // HR authentication
        'auth.login' => ['second_factor'],
        'auth.email_code_sent' => [],
        'auth.email_code_failed' => ['reason'],
        'auth.email_code_verified' => [],
        'auth.login_failed' => ['reason'],
        'auth.lockout' => [],
        'auth.logout' => [],
        'auth.password_reset' => [],
        'auth.password_changed' => [],
        'auth.two_factor_enabled' => [],
        'auth.two_factor_confirmed' => [],
        'auth.two_factor_disabled' => [],
        'auth.two_factor_failed' => [],
        'auth.recovery_codes_generated' => [],
        'auth.recovery_code_used' => [],

        // HR account management
        'hr_user.created' => ['via'],
        'hr_user.disabled' => [],
        'hr_user.enabled' => [],

        // Applicants (HR side)
        'applicant.created' => [],
        'applicant.updated' => ['fields'],
        'invitation.issued' => ['reissue', 'expires_in_days'],
        'invitation.revoked' => ['reason'],
        'invitation.delivered' => [],
        'invitation.delivery_failed' => ['error'],

        // Applicant access (applicant side)
        'applicant.access_failed' => ['reason'],
        'applicant.otp_sent' => [],
        'applicant.otp_failed' => ['reason'],
        'applicant.signed_in' => [],
        'applicant.signed_out' => [],
        'applicant.session_ended' => ['reason'],

        // Consent
        'consent.version_created' => ['version'],
        'consent.version_activated' => ['version'],
        'applicant.consent_acknowledged' => ['version'],

        // Templates and configuration
        'template.created' => ['template'],
        'template.updated' => ['template', 'fields'],
        'template.field_added' => ['template', 'field', 'type'],
        'template.field_removed' => ['template', 'field'],
        'template.print_asset_uploaded' => ['template', 'sha256_prefix'],
        'template.signature_approval_recorded' => ['template', 'mode'],
        'settings.updated' => ['keys'],

        // Documents (applicant actions)
        'document.assigned' => ['template', 'required'],
        'document.unassigned' => ['template'],
        'document.draft_saved' => ['template'],
        'document.acknowledged' => ['template'],
        'document.uploaded' => ['template', 'version', 'size', 'sha256_prefix', 'scan', 'replacement'],
        'supporting.uploaded' => ['template', 'requirement', 'version', 'size', 'sha256_prefix', 'scan', 'replacement'],
        'document.submitted' => ['template', 'resubmission'],

        // Review and export (HR actions)
        'document.approved' => ['template'],
        'document.correction_requested' => ['template', 'scope'],
        'document.viewed' => ['template'],
        'sensitive_field.revealed' => ['template', 'field'],
        'file.downloaded' => ['template', 'kind', 'disposition'],
        'file.deleted' => ['template', 'kind', 'was_current'],
        'export.requested' => [],
        'export.ready' => ['file_count', 'size'],
        'export.failed' => ['error'],
        'export.downloaded' => [],
        'applicant.transferred' => ['retention_days'],
        'applicant.legal_hold_set' => [],
        'applicant.legal_hold_cleared' => [],

        // Lifecycle (system actions)
        'reminder.sent' => ['kind'],
        'retention.purged' => ['trigger', 'files_deleted'],
        'retention.purge_failed' => ['trigger', 'error'],
        'retention.skipped_legal_hold' => [],
        'exports.cleaned' => ['count'],
        'audit.pruned' => ['count', 'older_than_days'],
    ],

    // Maximum length of any string metadata value.
    'max_value_length' => 200,

];
