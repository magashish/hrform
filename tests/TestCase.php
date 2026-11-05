<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        // Never touch real storage from tests.
        Storage::fake('documents');
        Storage::fake('exports');
    }

    /** A fully set-up HR user: password + confirmed 2FA. */
    protected function hrUser(array $attributes = []): User
    {
        return User::factory()->withTwoFactor(self::TOTP_SECRET)->create($attributes);
    }

    /** Act as an HR user who has passed login, 2FA and a recent password confirmation. */
    protected function actingAsHr(?User $user = null): User
    {
        $user ??= $this->hrUser();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        return $user;
    }

    protected function currentTotp(string $secret = self::TOTP_SECRET): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }
}
