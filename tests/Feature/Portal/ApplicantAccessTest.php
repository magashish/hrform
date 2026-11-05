<?php

namespace Tests\Feature\Portal;

use App\Http\Controllers\Portal\AccessController;
use App\Mail\ApplicantOtpMail;
use App\Models\AuditEvent;
use App\Models\ConsentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

class ApplicantAccessTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private function signInFlow(string $code, string $email): ?string
    {
        $this->post('/portal/access', ['code' => $code])->assertRedirect('/portal/verify');
        $this->post('/portal/verify/email', ['email' => $email])->assertRedirect('/portal/verify');

        $otp = null;
        Mail::assertQueued(ApplicantOtpMail::class, function (ApplicantOtpMail $m) use (&$otp) {
            $otp = $m->code;

            return true;
        });

        return $otp;
    }

    public function test_full_sign_in_flow_with_consent_gate(): void
    {
        Mail::fake();
        $consent = $this->activeConsent();
        [$applicant, $code] = $this->applicantWithInvitation(['email' => 'pat@example.com']);

        // Lower-case and without dashes still works (normalized).
        $otp = $this->signInFlow(strtolower(str_replace('-', ' ', $code)), ' PAT@example.com ');
        $this->post('/portal/verify/code', ['otp' => $otp])->assertRedirect('/portal');

        $this->get('/portal')->assertRedirect('/portal/consent');
        $this->get('/portal/consent')->assertOk()->assertSee($consent->title);

        $this->post('/portal/consent', ['version_id' => $consent->id])->assertSessionHasErrors('acknowledge');
        $this->post('/portal/consent', ['version_id' => $consent->id, 'acknowledge' => '1'])->assertRedirect('/portal');

        $this->get('/portal')->assertOk()->assertSee('Hello, '.$applicant->first_name);

        $applicant->refresh();
        $this->assertSame('in_progress', $applicant->status->value);
        $this->assertDatabaseHas('applicant_consents', ['applicant_id' => $applicant->id, 'consent_version_id' => $consent->id]);
        $this->assertNotNull($applicant->invitations()->first()->first_used_at);
        $this->assertDatabaseHas('audit_events', ['event' => 'applicant.signed_in', 'actor_type' => 'applicant', 'actor_id' => $applicant->applicant_ref]);
        $this->assertSame(['version' => $consent->version], AuditEvent::where('event', 'applicant.consent_acknowledged')->first()->metadata);
    }

    public function test_invalid_expired_and_revoked_codes_get_the_same_generic_error(): void
    {
        [$expired, $expiredCode] = $this->applicantWithInvitation();
        $expired->invitations()->update(['expires_at' => now()->subMinute()]);
        [$revoked, $revokedCode] = $this->applicantWithInvitation();
        $revoked->invitations()->update(['revoked_at' => now()]);

        foreach (['not-a-code', str_repeat('A', 32), $expiredCode, $revokedCode] as $code) {
            $this->from('/portal/access')->post('/portal/access', ['code' => $code])
                ->assertRedirect('/portal/access')
                ->assertSessionHasErrors(['code' => AccessController::GENERIC_CODE_ERROR]);
        }

        $this->assertSame(1, $expired->invitations()->first()->failed_attempts);
        $this->assertDatabaseHas('audit_events', ['event' => 'applicant.access_failed', 'applicant_ref' => $expired->applicant_ref]);
    }

    public function test_code_expires_after_seven_days(): void
    {
        [$applicant, $code] = $this->applicantWithInvitation();

        $this->travel(7)->days();
        $this->travel(1)->minutes();

        $this->post('/portal/access', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_code_entry_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/portal/access', ['code' => 'wrong-'.$i]);
        }

        $this->post('/portal/access', ['code' => 'wrong-again'])->assertStatus(429);
    }

    public function test_wrong_email_sends_nothing_but_shows_the_same_message(): void
    {
        Mail::fake();
        [$applicant, $code] = $this->applicantWithInvitation(['email' => 'right@example.com']);

        $this->post('/portal/access', ['code' => $code]);
        $wrong = $this->post('/portal/verify/email', ['email' => 'wrong@example.com']);
        $wrongNotice = session('portal_notice');

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('audit_events', ['event' => 'applicant.otp_failed']);

        $this->post('/portal/verify/email', ['email' => 'right@example.com']);
        $this->assertSame($wrongNotice, session('portal_notice'));
        Mail::assertQueued(ApplicantOtpMail::class, 1);
    }

    public function test_otp_attempts_are_limited(): void
    {
        Mail::fake();
        [$applicant, $code] = $this->applicantWithInvitation(['email' => 'a@example.com']);
        $otp = $this->signInFlow($code, 'a@example.com');
        $wrong = $otp === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/portal/verify/code', ['otp' => $wrong])->assertSessionHasErrors('otp');
        }

        // Even the right code is refused now.
        $this->post('/portal/verify/code', ['otp' => $otp])->assertSessionHasErrors(['otp' => AccessController::GENERIC_OTP_ERROR]);
    }

    public function test_otp_expires(): void
    {
        Mail::fake();
        [$applicant, $code] = $this->applicantWithInvitation(['email' => 'a@example.com']);
        $otp = $this->signInFlow($code, 'a@example.com');

        $this->travel(11)->minutes();

        $this->post('/portal/verify/code', ['otp' => $otp])->assertSessionHasErrors('otp');
    }

    public function test_otp_is_not_stored_in_plain_text(): void
    {
        Mail::fake();
        [$applicant, $code] = $this->applicantWithInvitation(['email' => 'a@example.com']);
        $otp = $this->signInFlow($code, 'a@example.com');

        $row = \DB::table('applicant_otps')->first();
        $this->assertNotSame($otp, $row->code_hash);
        $this->assertStringNotContainsString($otp, json_encode($row));
    }

    public function test_revoking_the_invitation_ends_the_applicant_session(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $invitation = $this->signInApplicant($applicant);

        $this->get('/portal')->assertOk();

        $invitation->forceFill(['revoked_at' => now()])->save();

        $this->get('/portal')->assertRedirect('/portal/access');
        $this->get('/portal')->assertRedirect('/portal/access'); // session cleared
        $this->assertDatabaseHas('audit_events', ['event' => 'applicant.session_ended']);
    }

    public function test_idle_timeout_ends_the_session(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $this->signInApplicant($applicant);
        $this->get('/portal')->assertOk();

        $this->travel(31)->minutes();

        $this->get('/portal')->assertRedirect('/portal/access');
    }

    public function test_new_consent_version_must_be_acknowledged_again(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $this->signInApplicant($applicant);
        $this->get('/portal')->assertOk();

        $this->activeConsent()->forceFill(['active' => false])->save();
        ConsentVersion::factory()->active()->create(['version' => 'v2']);

        $this->get('/portal')->assertRedirect('/portal/consent');
    }

    public function test_portal_pages_require_a_session(): void
    {
        $this->get('/portal')->assertRedirect('/portal/access');
        $this->get('/portal/consent')->assertRedirect('/portal/access');
        $this->get('/portal/verify')->assertRedirect('/portal/access');
    }

    public function test_applicant_session_cannot_reach_hr_pages(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $this->signInApplicant($applicant);

        $this->get('/hr')->assertRedirect('/hr/login');
        $this->get("/hr/applicants/{$applicant->public_id}")->assertRedirect('/hr/login');
    }

    public function test_sign_out_keeps_progress(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $this->signInApplicant($applicant);

        $this->post('/portal/logout')->assertRedirect('/portal/access');
        $this->get('/portal')->assertRedirect('/portal/access');
        $this->assertNotNull($applicant->fresh()->consented_at);
    }
}
