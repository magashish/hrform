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
        // HR users must enrol and use TOTP 2FA before reaching any HR page.
        'two_factor_required' => (bool) env('HR_2FA_REQUIRED', true),

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
