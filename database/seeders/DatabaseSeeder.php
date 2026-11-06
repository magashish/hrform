<?php

namespace Database\Seeders;

use App\Models\ConsentVersion;
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

        $this->call(DefaultTemplatesSeeder::class);

        if (! ConsentVersion::exists()) {
            $consent = new ConsentVersion([
                'version' => 'DRAFT-1',
                'title' => 'Disclosure and consent (DRAFT: client wording pending)',
                'body' => "PLACEHOLDER TEXT FOR DEVELOPMENT ONLY. The final disclosure wording will be supplied by the client after HR consultant review.\n\nWe collect the information and documents you provide in this portal to complete your onboarding. Access is limited to authorized HR staff, the information is protected in transit and at rest, and it is deleted from this portal after it has been transferred to our HR system.",
            ]);
            $consent->forceFill(['active' => true, 'effective_at' => now()])->save();
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
        $this->command?->info('Development HR user seeded (second factor required at sign-in).');
    }
}
