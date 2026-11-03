<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HrUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_user_can_list_users(): void
    {
        $other = $this->hrUser(['name' => 'Second HR']);
        $this->actingAsHr();

        $this->get('/hr/users')->assertOk()->assertSee('Second HR')->assertSee($other->email);
    }

    public function test_creating_a_user_requires_password_confirmation(): void
    {
        $this->actingAs($this->hrUser());

        $this->post('/hr/users', ['name' => 'New HR', 'email' => 'new@example.com'])
            ->assertRedirect('/hr/user/confirm-password');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_hr_user_can_create_another_and_a_set_password_link_is_sent(): void
    {
        Notification::fake();
        $actor = $this->actingAsHr();

        $this->post('/hr/users', ['name' => 'New HR', 'email' => 'New@Example.com'])
            ->assertRedirect('/hr/users');

        $created = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertFalse($created->hasConfirmedTwoFactor());

        Notification::assertSentTo($created, ResetPassword::class, function (ResetPassword $n) use ($created) {
            $url = $n->toMail($created)->actionUrl;

            // Reset URL must not carry the email address in its query string.
            return ! str_contains($url, 'email=') && ! str_contains($url, urlencode($created->email));
        });

        $this->assertDatabaseHas('audit_events', [
            'event' => 'hr_user.created',
            'actor_id' => (string) $actor->id,
            'subject_id' => (string) $created->id,
        ]);
    }

    public function test_create_validates_input(): void
    {
        $existing = $this->hrUser();
        $this->actingAsHr();

        $this->post('/hr/users', ['name' => '', 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['name', 'email']);

        $this->post('/hr/users', ['name' => 'Dup', 'email' => $existing->email])
            ->assertSessionHasErrors('email');
    }

    public function test_hr_user_cannot_disable_themselves(): void
    {
        $actor = $this->actingAsHr();

        $this->post("/hr/users/{$actor->id}/disable")->assertForbidden();

        $this->assertFalse($actor->fresh()->isDisabled());
    }

    public function test_disable_and_enable_another_user(): void
    {
        $target = $this->hrUser();
        $actor = $this->actingAsHr();

        $this->post("/hr/users/{$target->id}/disable")->assertRedirect();
        $this->assertTrue($target->fresh()->isDisabled());
        $this->assertDatabaseHas('audit_events', ['event' => 'hr_user.disabled', 'subject_id' => (string) $target->id, 'actor_id' => (string) $actor->id]);

        $this->post("/hr/users/{$target->id}/enable")->assertRedirect();
        $this->assertFalse($target->fresh()->isDisabled());
        $this->assertDatabaseHas('audit_events', ['event' => 'hr_user.enabled', 'subject_id' => (string) $target->id]);
    }

    public function test_disable_requires_password_confirmation(): void
    {
        $target = $this->hrUser();
        $this->actingAs($this->hrUser());

        $this->post("/hr/users/{$target->id}/disable")->assertRedirect('/hr/user/confirm-password');
        $this->assertFalse($target->fresh()->isDisabled());
    }
}
