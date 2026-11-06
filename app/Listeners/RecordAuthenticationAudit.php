<?php

namespace App\Listeners;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Records HR authentication activity. Never records submitted emails,
 * passwords, codes or tokens.
 */
class RecordAuthenticationAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        // With the email method the code step follows this event (auth.email_code_verified).
        $this->audit->record('auth.login', $event->user, [
            'second_factor' => config('portal.hr.two_factor_method') === 'totp'
                ? ($event->user->hasConfirmedTwoFactor() ? 'totp' : 'totp_not_enrolled')
                : 'email_code_pending',
        ], hrActor: $event->user);
    }

    public function onFailed(Failed $event): void
    {
        // The submitted email is used only to find the targeted account; it
        // is never written to the audit log itself.
        $user = $event->user instanceof User ? $event->user : null;
        $email = $event->credentials['email'] ?? null;

        if (! $user && is_string($email) && $email !== '') {
            $user = User::where('email', mb_strtolower($email))->first();
        }

        $this->audit->record(
            'auth.login_failed',
            $user,
            ['reason' => match (true) {
                $user === null => 'unknown_account',
                $user->isDisabled() => 'account_disabled',
                default => 'invalid_credentials',
            }],
            actorType: AuditEvent::ACTOR_SYSTEM,
        );
    }

    public function onLockout(Lockout $event): void
    {
        $this->audit->record('auth.lockout', actorType: AuditEvent::ACTOR_SYSTEM);
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.logout', $event->user, hrActor: $event->user);
        }
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.password_reset', $event->user, hrActor: $event->user);
        }
    }

    public function onTwoFactor(object $event): void
    {
        $name = match (true) {
            $event instanceof TwoFactorAuthenticationEnabled => 'auth.two_factor_enabled',
            $event instanceof TwoFactorAuthenticationConfirmed => 'auth.two_factor_confirmed',
            $event instanceof TwoFactorAuthenticationDisabled => 'auth.two_factor_disabled',
            $event instanceof TwoFactorAuthenticationFailed => 'auth.two_factor_failed',
            $event instanceof RecoveryCodesGenerated => 'auth.recovery_codes_generated',
            $event instanceof RecoveryCodeReplaced => 'auth.recovery_code_used',
            default => null,
        };

        $user = $event->user ?? null;

        if ($name && $user instanceof User) {
            $this->audit->record($name, $user, hrActor: $user);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Logout::class => 'onLogout',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationEnabled::class => 'onTwoFactor',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactor',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactor',
            TwoFactorAuthenticationFailed::class => 'onTwoFactor',
            RecoveryCodesGenerated::class => 'onTwoFactor',
            RecoveryCodeReplaced::class => 'onTwoFactor',
        ];
    }
}
