<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTotp();
    }

    public function test_user_without_two_factor_is_forced_to_enrol(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/hr')->assertRedirect(route('hr.security.two-factor'));
        $this->get('/hr/users')->assertRedirect(route('hr.security.two-factor'));
        $this->get('/hr/users/create')->assertRedirect(route('hr.security.two-factor'));
        $this->post('/hr/users', ['name' => 'X', 'email' => 'x@example.com'])
            ->assertRedirect(route('hr.security.two-factor'));
    }

    public function test_setup_page_requires_recent_password_confirmation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/hr/security/two-factor')->assertRedirect('/hr/user/confirm-password');
    }

    public function test_full_enrolment_flow_unlocks_hr_area(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get('/hr/security/two-factor')->assertOk()->assertSee('Set up two-factor authentication');

        $this->post('/hr/user/two-factor-authentication')->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        // Still locked out until the code is confirmed.
        $this->get('/hr')->assertRedirect(route('hr.security.two-factor'));

        $this->get('/hr/security/two-factor')->assertOk()->assertSee('Confirm and enable');

        $code = (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));
        $this->post('/hr/user/confirmed-two-factor-authentication', ['code' => $code])
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        // Recovery codes shown once, straight after confirmation.
        $codes = $user->fresh()->recoveryCodes();
        $this->get('/hr/security/two-factor')->assertSee($codes[0]);
        $this->get('/hr/security/two-factor')->assertDontSee($codes[0]);

        $this->get('/hr')->assertOk();

        $this->assertDatabaseHas('audit_events', ['event' => 'auth.two_factor_enabled', 'subject_id' => (string) $user->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.two_factor_confirmed', 'subject_id' => (string) $user->id]);
    }

    public function test_two_factor_cannot_be_disabled_when_required(): void
    {
        $user = $this->actingAsHr();

        $this->delete('/hr/user/two-factor-authentication')->assertForbidden();

        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());
    }

    public function test_enforcement_can_be_switched_off_by_configuration(): void
    {
        config(['portal.hr.two_factor_required' => false]);

        $this->actingAs(User::factory()->create());

        $this->get('/hr')->assertOk();
    }
}
