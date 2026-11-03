<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Hr\HrUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Technical-operator command for creating HR accounts (e.g. the first one).
 * The password is prompted interactively and is never accepted as a CLI
 * argument, so it cannot leak into shell history or process lists.
 */
class CreateHrUser extends Command
{
    protected $signature = 'portal:hr-user:create
        {email : HR user email address}
        {--name= : Display name}
        {--send-link : Email a set-password link instead of prompting for a password}';

    protected $description = 'Create an HR user account';

    public function handle(HrUserService $users): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));

        $validator = Validator::make(compact('email', 'name'), [
            'email' => ['required', 'max:254', 'email:strict', Rule::unique(User::class, 'email')],
            'name' => ['required', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = null;

        if (! $this->option('send-link')) {
            $password = (string) $this->secret('Password');
            $confirm = (string) $this->secret('Confirm password');

            $pwValidator = Validator::make(
                ['password' => $password, 'password_confirmation' => $confirm],
                ['password' => ['required', 'confirmed', Password::defaults()]],
            );

            if ($pwValidator->fails()) {
                foreach ($pwValidator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }
        }

        $users->create($name, $email, $password, via: 'cli');

        $this->info('HR user created.'.($password === null ? ' A set-password link has been emailed.' : ''));
        $this->line('Two-factor enrolment will be required at first login.');

        return self::SUCCESS;
    }
}
