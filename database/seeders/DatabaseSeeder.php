<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Hr\HrUserService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local development seeding only. Never seeds credentials in production:
 * use `php artisan portal:hr-user:create` there.
 */
class DatabaseSeeder extends Seeder
{
    public function run(HrUserService $users): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DatabaseSeeder must not be run in production.');
        }

        $email = env('DEV_HR_EMAIL');
        $password = env('DEV_HR_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('DEV_HR_EMAIL / DEV_HR_PASSWORD not set: no development HR user seeded.');

            return;
        }

        if (User::where('email', mb_strtolower($email))->exists()) {
            $this->command?->info('Development HR user already exists.');

            return;
        }

        $users->create('Development HR', $email, $password, via: 'seeder');
        $this->command?->info('Development HR user seeded (2FA enrolment required at first login).');
    }
}
