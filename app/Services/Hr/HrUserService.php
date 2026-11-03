<?php

namespace App\Services\Hr;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class HrUserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create an HR account. When no password is given, the account gets an
     * unusable random password and the new user is emailed a link to set
     * their own, so no HR user ever knows another user's password.
     */
    public function create(string $name, string $email, ?string $password = null, ?User $createdBy = null, string $via = 'web'): User
    {
        $user = DB::transaction(function () use ($name, $email, $password, $createdBy, $via) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => Hash::make($password ?? Str::random(64)),
            ])->save();

            $this->audit->record(
                'hr_user.created',
                $user,
                ['via' => $via],
                hrActor: $createdBy,
                actorType: $createdBy ? AuditEvent::ACTOR_HR : AuditEvent::ACTOR_SYSTEM,
                actorId: $createdBy ? (string) $createdBy->getKey() : null,
            );

            return $user;
        });

        if ($password === null) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return $user;
    }

    public function disable(User $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor) {
            $target->forceFill([
                'disabled_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            // End any database-backed sessions for the disabled user.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $target->getKey())->delete();
            }

            $this->audit->record('hr_user.disabled', $target, hrActor: $actor);
        });
    }

    public function enable(User $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor) {
            $target->forceFill(['disabled_at' => null])->save();
            $this->audit->record('hr_user.enabled', $target, hrActor: $actor);
        });
    }
}
