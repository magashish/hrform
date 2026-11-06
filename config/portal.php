<?php

/*
|--------------------------------------------------------------------------
| Onboarding portal configuration
|--------------------------------------------------------------------------
|
| Defaults here are deliberately conservative and are documented in
| PROJECT_ANALYSIS.md. Values marked "pending client confirmation" are
| interim defaults: see OPEN_QUESTIONS.md before changing them.
|
*/

return [

    'hr' => [
        // A second factor is required before any HR page.
        'two_factor_required' => (bool) env('HR_2FA_REQUIRED', true),

        // "email": a one-time code is emailed at every sign-in (client's choice).
        // "totp":  authenticator app (Fortify), enrolled once per user.
        'two_factor_method' => in_array(env('HR_2FA_METHOD', 'email'), ['email', 'totp'], true) ? env('HR_2FA_METHOD', 'email') : 'email',

        // Email sign-in codes.
        'email_code_ttl_minutes' => (int) env('HR_EMAIL_CODE_TTL_MINUTES', 10),
        'email_code_max_attempts' => (int) env('HR_EMAIL_CODE_MAX_ATTEMPTS', 5),
        'email_code_max_sends' => (int) env('HR_EMAIL_CODE_MAX_SENDS', 3),   // per 15 minutes

        // Minimum password length for HR accounts.
        'password_min_length' => (int) env('HR_PASSWORD_MIN_LENGTH', 12),

        // Check new HR passwords against the Have I Been Pwned range API.
        // Requires outbound HTTPS from the server; off by default.
        'password_check_uncompromised' => (bool) env('HR_PASSWORD_CHECK_UNCOMPROMISED', false),
    ],

    'invitations' => [
        // Confirmed client requirement: codes expire after seven days.
        'expiry_days' => (int) env('INVITATION_EXPIRY_DAYS', 7),
    ],

    'applicant_access' => [
        // Email one-time passcode used after the invitation code (OPEN_QUESTIONS #7).
        'otp_ttl_minutes' => (int) env('APPLICANT_OTP_TTL_MINUTES', 10),
        'otp_max_attempts' => (int) env('APPLICANT_OTP_MAX_ATTEMPTS', 5),
        'otp_max_sends_per_window' => (int) env('APPLICANT_OTP_MAX_SENDS', 3),
        'otp_send_window_minutes' => 15,

        // Applicant session limits.
        'idle_timeout_minutes' => (int) env('APPLICANT_IDLE_TIMEOUT_MINUTES', 30),
        'absolute_timeout_minutes' => (int) env('APPLICANT_ABSOLUTE_TIMEOUT_MINUTES', 480),

        // Rate limits (attempts per minute).
        'code_attempts_per_minute' => (int) env('APPLICANT_CODE_ATTEMPTS_PER_MINUTE', 10),
    ],

    'files' => [
        // Default generated filename pattern (OPEN_QUESTIONS #6). Editable in HR settings.
        'filename_pattern' => env('FILENAME_PATTERN', '{last_name}_{first_name}_{document_type}_{date}'),
        'filename_date_format' => env('FILENAME_DATE_FORMAT', 'Ymd'),

        // Global upload allowlist. Templates may narrow it, never widen it.
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'heic'],
        'default_max_upload_kb' => (int) env('UPLOAD_DEFAULT_MAX_KB', 10240),
        'hard_max_upload_kb' => (int) env('UPLOAD_HARD_MAX_KB', 25600),

        // Delete superseded (corrected) file versions immediately instead of at purge (#18).
        'delete_superseded' => (bool) env('DELETE_SUPERSEDED_FILES', false),
    ],

    'scanner' => [
        // none | clamav. "none" records uploads as not_scanned (documented deployment risk).
        'driver' => env('MALWARE_SCANNER', 'none'),
        'clamav_socket' => env('CLAMAV_SOCKET'),            // e.g. unix:///var/run/clamav/clamd.ctl
        'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),
        'clamav_port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        // Reject uploads when the scanner errors (fail closed).
        'fail_closed' => (bool) env('MALWARE_SCANNER_FAIL_CLOSED', true),
    ],

    'reminders' => [
        'days_before_expiry' => env('REMINDER_DAYS_BEFORE_EXPIRY', '3,1'),
        'incomplete_every_days' => (int) env('REMINDER_INCOMPLETE_EVERY_DAYS', 3),
    ],

    'notifications' => [
        // Empty = all active HR users. Otherwise comma-separated addresses.
        'hr_recipients' => env('HR_NOTIFICATION_RECIPIENTS', ''),
        'hr_events' => ['document_submitted', 'supporting_uploaded', 'packet_completed', 'delivery_failed', 'purge_failed'],
        'available_hr_events' => [
            'document_submitted' => 'An applicant submits a document',
            'supporting_uploaded' => 'An applicant uploads or replaces a file',
            'packet_completed' => 'An applicant completes all required documents',
            'delivery_failed' => 'An invitation email cannot be delivered',
            'purge_failed' => 'An automatic deletion fails',
        ],
    ],

    'retention' => [
        // Days after HR marks a packet transferred before it is purged (#10).
        'days' => (int) env('RETENTION_DAYS', 60),
        'max_days' => 60,
        // Set only after written client approval to exceed 60 days.
        'allow_over_max' => (bool) env('RETENTION_ALLOW_OVER_MAX', false),
        // Off by default: the scheduler only produces dry-run reports until enabled.
        'auto_purge' => (bool) env('RETENTION_AUTO_PURGE', false),
        // Abandoned (never transferred) applications: days since last activity; 0 = disabled (pending approval).
        'abandoned_days' => (int) env('RETENTION_ABANDONED_DAYS', 0),
    ],

    'exports' => [
        // Minutes a generated ZIP stays available before cleanup.
        'ttl_minutes' => (int) env('EXPORT_TTL_MINUTES', 60),
    ],

    'esign' => [
        // No provider has been selected (OPEN_QUESTIONS #4, #14).
        'provider' => env('ESIGN_PROVIDER', 'disabled'),
        'webhook_secret' => env('ESIGN_WEBHOOK_SECRET'),
    ],

    'audit' => [
        // Record IP address / user agent on audit events (OPEN_QUESTIONS #16).
        'record_ip' => (bool) env('AUDIT_RECORD_IP', true),
        'record_user_agent' => (bool) env('AUDIT_RECORD_USER_AGENT', true),

        // Pseudonymous audit retention in days (pending client confirmation, #11).
        'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
    ],

    'storage' => [
        // Private disk for applicant documents. Never a public disk.
        'documents_disk' => env('DOCUMENTS_DISK', 'documents'),
        'exports_disk' => env('EXPORTS_DISK', 'exports'),
    ],

    // Comma-separated proxy IPs/CIDRs, or "*". Empty = trust none.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'security' => [
        // Send HSTS when the request is HTTPS. Enable only once HTTPS is
        // permanently in place for the domain.
        'hsts' => (bool) env('SECURITY_HSTS', false),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    ],

];
