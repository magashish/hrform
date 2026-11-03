<?php

namespace Tests\Feature\Hr;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_is_identical_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        $user = $this->hrUser();

        $known = $this->from('/hr/forgot-password')->post('/hr/forgot-password', ['email' => $user->email]);
        $unknown = $this->from('/hr/forgot-password')->post('/hr/forgot-password', ['email' => 'nobody@example.com']);

        foreach ([$known, $unknown] as $response) {
            $response->assertRedirect('/hr/forgot-password')
                ->assertSessionHas('status', 'reset-link-requested')
                ->assertSessionHasNoErrors();
        }

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_reset_link_requests_are_rate_limited_per_ip(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/hr/forgot-password', ['email' => "n{$i}@example.com"]);
        }

        $this->post('/hr/forgot-password', ['email' => 'n6@example.com'])->assertStatus(429);
    }

    public function test_password_can_be_reset_with_strong_password_and_is_audited(): void
    {
        Notification::fake();
        $user = $this->hrUser();

        $this->post('/hr/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get('/hr/reset-password/'.$token)->assertOk();

        $this->post('/hr/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->post('/hr/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'A-Much-Better-Passw0rd!', 'password_confirmation' => 'A-Much-Better-Passw0rd!',
        ])->assertRedirect('/hr/login');

        $this->assertDatabaseHas('audit_events', ['event' => 'auth.password_reset', 'subject_id' => (string) $user->id]);
    }
}
