<?php

namespace App\Services\Hr;

use App\Mail\HrLoginCodeMail;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Second sign-in factor for HR users: a 6-digit code emailed at each login.
 * The pass is stored in the session against the user's ID and is cleared by
 * logout, session expiry or a new login.
 */
class HrEmailCodeService
{
    public const SESSION_PASSED = 'hr.second_factor_passed';

    public const SESSION_SENT = 'hr.second_factor_sent';

    public function __construct(private readonly AuditLogger $audit) {}

    public static function usesEmail(): bool
    {
        return config('portal.hr.two_factor_required') && config('portal.hr.two_factor_method') === 'email';
    }

    public function hasPassed(Request $request, User $user): bool
    {
        return (int) $request->session()->get(self::SESSION_PASSED) === $user->id;
    }

    /**
     * @return bool false when the send limit was reached or mail failed
     */
    public function send(Request $request, User $user): bool
    {
        $recent = DB::table('hr_login_codes')->where('user_id', $user->id)
            ->where('created_at', '>', now()->subMinutes(15))->count();

        if ($recent >= (int) config('portal.hr.email_code_max_sends', 3)) {
            $this->audit->record('auth.email_code_failed', $user, ['reason' => 'send_limit'], hrActor: $user);

            return false;
        }

        // Only the newest code is valid.
        DB::table('hr_login_codes')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = (int) config('portal.hr.email_code_ttl_minutes', 10);

        DB::table('hr_login_codes')->insert([
            'user_id' => $user->id,
            'code_hash' => $this->hash($user, $code),
            'expires_at' => now()->addMinutes($minutes),
            'created_at' => now(),
        ]);

        try {
            Mail::to($user->email)->send(new HrLoginCodeMail($code, $minutes));
        } catch (Throwable $e) {
            report($e);
            $this->audit->record('auth.email_code_failed', $user, ['reason' => 'mail_error'], hrActor: $user);

            return false;
        }

        $request->session()->put(self::SESSION_SENT, $user->id);
        $this->audit->record('auth.email_code_sent', $user, hrActor: $user);

        return true;
    }

    public function verify(Request $request, User $user, string $code): bool
    {
        $row = DB::table('hr_login_codes')->where('user_id', $user->id)
            ->whereNull('consumed_at')->latest('id')->first();
        $max = (int) config('portal.hr.email_code_max_attempts', 5);
        $code = preg_replace('/\D/', '', $code) ?? '';

        $reason = match (true) {
            $row === null => 'no_active_code',
            now()->greaterThan($row->expires_at) => 'expired',
            $row->attempts >= $max => 'too_many_attempts',
            ! hash_equals($row->code_hash, $this->hash($user, $code)) => 'wrong_code',
            default => null,
        };

        if ($reason !== null) {
            if ($row && $reason === 'wrong_code') {
                DB::table('hr_login_codes')->where('id', $row->id)->increment('attempts');
            }
            $this->audit->record('auth.email_code_failed', $user, ['reason' => $reason], hrActor: $user);

            return false;
        }

        DB::table('hr_login_codes')->where('id', $row->id)->update(['consumed_at' => now()]);

        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_SENT);
        $request->session()->put(self::SESSION_PASSED, $user->id);
        $this->audit->record('auth.email_code_verified', $user, hrActor: $user);

        return true;
    }

    private function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', 'hr-login|'.$user->id.'|'.$code, (string) config('app.key'));
    }
}
