<?php

namespace Tests\Feature\Hr;

use App\Mail\HrLoginCodeMail;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Default HR second factor: a one-time code emailed at every sign-in
 * (HR_2FA_METHOD=email). No authenticator app is involved.
 */
class EmailCodeLoginTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): void
    {
        $this->post('/hr/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!'])->assertRedirect('/hr');
    }

    private function lastCode(): string
    {
        $code = null;
        Mail::assertSent(HrLoginCodeMail::class, function (HrLoginCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    }

    public function test_password_then_emailed_code_unlocks_hr_area(): void
    {
        Mail::fake();
        // A user with no authenticator app at all.
        $user = User::factory()->create(['password' => 'Correct-Horse-9!']);

        $this->login($user);
        $this->get('/hr')->assertRedirect('/hr/verify-code');
        $this->get('/hr/applicants')->assertRedirect('/hr/verify-code');

        $this->get('/hr/verify-code')->assertOk()->assertSee('Check your email')->assertDontSee($user->email);
        Mail::assertSent(HrLoginCodeMail::class, fn ($m) => $m->hasTo($user->email));
        $code = $this->lastCode();

        // Reloading the page does not send another code.
        $this->get('/hr/verify-code');
        Mail::assertSent(HrLoginCodeMail::class, 1);

        $this->post('/hr/verify-code', ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->post('/hr/verify-code', ['code' => $code])->assertRedirect('/hr/applicants');

        $this->get('/hr')->assertOk();
        $this->get('/hr/verify-code')->assertRedirect('/hr');

        // Code stored only as a hash; audit has no code.
        $this->assertStringNotContainsString($code, json_encode(DB::table('hr_login_codes')->get()));
        $this->assertTrue(AuditEvent::where('event', 'auth.email_code_verified')->exists());
        $this->assertStringNotContainsString($code, json_encode(AuditEvent::all()));
    }

    public function test_no_authenticator_challenge_even_if_user_had_enrolled_one(): void
    {
        Mail::fake();
        $user = $this->hrUser(['password' => 'Correct-Horse-9!']); // has a TOTP secret

        $this->post('/hr/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!'])->assertRedirect('/hr');
        $this->assertAuthenticatedAs($user);
        $this->get('/hr')->assertRedirect('/hr/verify-code');
    }

    public function test_code_is_valid_only_for_its_lifetime_and_attempt_limit(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'Correct-Horse-9!']);
        $this->login($user);
        $this->get('/hr/verify-code');
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/hr/verify-code', ['code' => $wrong]);
        }
        $this->post('/hr/verify-code', ['code' => $code])->assertSessionHasErrors('code');

        // A fresh code works, but not after it expires.
        $this->post('/hr/verify-code/resend')->assertRedirect();
        $codes = [];
        Mail::assertSent(HrLoginCodeMail::class, function ($m) use (&$codes) {
            $codes[] = $m->code;

            return true;
        });
        $this->travel(11)->minutes();
        $this->post('/hr/verify-code', ['code' => end($codes)])->assertSessionHasErrors('code');
    }

    public function test_resends_are_limited(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'Correct-Horse-9!']);
        $this->login($user);
        $this->get('/hr/verify-code');               // 1
        $this->post('/hr/verify-code/resend');        // 2
        $this->post('/hr/verify-code/resend');        // 3
        $this->post('/hr/verify-code/resend')->assertSessionHas('code_error');

        Mail::assertSent(HrLoginCodeMail::class, 3);
    }

    public function test_a_code_for_one_user_does_not_work_for_another(): void
    {
        Mail::fake();
        $alice = User::factory()->create(['password' => 'Correct-Horse-9!']);
        $bob = User::factory()->create(['password' => 'Correct-Horse-9!']);

        $this->login($alice);
        $this->get('/hr/verify-code');
        $aliceCode = $this->lastCode();
        $this->post('/hr/logout');

        $this->login($bob);
        $this->post('/hr/verify-code', ['code' => $aliceCode])->assertSessionHasErrors('code');
        $this->get('/hr')->assertRedirect('/hr/verify-code');
    }

    public function test_logging_out_requires_a_new_code_next_time(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'Correct-Horse-9!']);
        $this->login($user);
        $this->get('/hr/verify-code');
        $this->post('/hr/verify-code', ['code' => $this->lastCode()]);
        $this->get('/hr')->assertOk();

        $this->post('/hr/logout');
        $this->login($user);

        $this->get('/hr')->assertRedirect('/hr/verify-code');
    }

    public function test_security_page_explains_email_codes(): void
    {
        $user = $this->actingAsHr();

        $this->get('/hr/security/two-factor')->assertOk()
            ->assertSee('a 6-digit code is emailed to', false)
            ->assertDontSee('Set up two-factor authentication');
    }
}
