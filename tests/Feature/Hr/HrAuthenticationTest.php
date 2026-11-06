<?php

namespace Tests\Feature\Hr;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTotp();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/hr/login')->assertOk()->assertSee('HR sign in');
    }

    public function test_guests_are_redirected_from_hr_pages(): void
    {
        $this->get('/hr')->assertRedirect('/hr/login');
        $this->get('/hr/users')->assertRedirect('/hr/login');
        $this->get('/hr/security/two-factor')->assertRedirect('/hr/login');
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/hr/register')->assertNotFound();
        $this->post('/hr/register', [])->assertNotFound();
        $this->get('/register')->assertNotFound();
    }

    public function test_user_with_two_factor_must_pass_challenge(): void
    {
        $user = $this->hrUser(['password' => 'Correct-Horse-9!']);

        $this->post('/hr/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!'])
            ->assertRedirect('/hr/two-factor-challenge');

        $this->assertGuest();

        $this->post('/hr/two-factor-challenge', ['code' => '000000'])
            ->assertRedirect('/hr/two-factor-challenge');
        $this->assertGuest();

        $this->post('/hr/two-factor-challenge', ['code' => $this->currentTotp()])
            ->assertRedirect('/hr');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_recovery_code_can_complete_challenge_once(): void
    {
        $user = $this->hrUser(['password' => 'Correct-Horse-9!']);

        $this->post('/hr/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!']);
        $this->post('/hr/two-factor-challenge', ['recovery_code' => 'recovery-code-one'])
            ->assertRedirect('/hr');

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('recovery-code-one', $user->fresh()->recoveryCodes());
    }

    public function test_wrong_password_fails_generically(): void
    {
        $user = $this->hrUser();

        $this->from('/hr/login')
            ->post('/hr/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/hr/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        $user = User::factory()->withTwoFactor()->disabled()->create(['password' => 'Correct-Horse-9!']);

        $this->post('/hr/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_disabled_user_is_logged_out_mid_session(): void
    {
        $user = $this->hrUser();
        $this->actingAs($user);

        $user->forceFill(['disabled_at' => now()])->save();

        $this->get('/hr')->assertRedirect('/hr/login');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->hrUser();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/hr/login', ['email' => $user->email, 'password' => 'wrong-'.$i]);
        }

        $this->post('/hr/login', ['email' => $user->email, 'password' => 'wrong-again'])
            ->assertStatus(429);
    }

    public function test_login_and_failure_are_audited_without_credentials(): void
    {
        $user = $this->hrUser(['password' => 'Correct-Horse-9!']);

        $this->post('/hr/login', ['email' => $user->email, 'password' => 'Wrong-Password-1!']);
        $this->post('/hr/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!']);
        $this->post('/hr/two-factor-challenge', ['code' => $this->currentTotp()]);

        $this->assertDatabaseHas('audit_events', ['event' => 'auth.login_failed', 'subject_id' => (string) $user->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.login', 'actor_type' => 'hr', 'actor_id' => (string) $user->id]);

        foreach (AuditEvent::all() as $event) {
            $json = json_encode($event->getAttributes());
            $this->assertStringNotContainsString('Correct-Horse-9!', $json);
            $this->assertStringNotContainsString('Wrong-Password-1!', $json);
            $this->assertStringNotContainsString($user->email, $json);
        }
    }

    public function test_logout_is_audited(): void
    {
        $user = $this->actingAsHr();

        $this->post('/hr/logout')->assertRedirect();

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.logout', 'actor_id' => (string) $user->id]);
    }
}
