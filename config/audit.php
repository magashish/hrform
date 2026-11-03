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
        'auth.login' => ['two_factor'],
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
    ],

    // Maximum length of any string metadata value.
    'max_value_length' => 200,

];
