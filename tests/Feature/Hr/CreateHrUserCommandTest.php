<?php

namespace Tests\Feature\Hr;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreateHrUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_user_with_prompted_password(): void
    {
        $this->artisan('portal:hr-user:create', ['email' => 'Ops@Example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password', 'Strong-Passw0rd!')
            ->expectsQuestion('Confirm password', 'Strong-Passw0rd!')
            ->assertSuccessful();

        $user = User::where('email', 'ops@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Strong-Passw0rd!', $user->password));
        $this->assertDatabaseHas('audit_events', ['event' => 'hr_user.created', 'actor_type' => 'system', 'subject_id' => (string) $user->id]);
        $this->assertSame(['via' => 'cli'], AuditEvent::where('event', 'hr_user.created')->first()->metadata);
    }

    public function test_rejects_weak_password(): void
    {
        $this->artisan('portal:hr-user:create', ['email' => 'ops@example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password', 'weak')
            ->expectsQuestion('Confirm password', 'weak')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'ops@example.com']);
    }

    public function test_rejects_duplicate_email(): void
    {
        $existing = $this->hrUser();

        $this->artisan('portal:hr-user:create', ['email' => $existing->email, '--name' => 'Dup'])
            ->assertFailed();
    }

    public function test_send_link_option_emails_set_password_link(): void
    {
        Notification::fake();

        $this->artisan('portal:hr-user:create', ['email' => 'ops@example.com', '--name' => 'Ops', '--send-link' => true])
            ->assertSuccessful();

        Notification::assertSentTo(User::where('email', 'ops@example.com')->first(), ResetPassword::class);
    }
}
