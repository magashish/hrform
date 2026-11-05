<?php

namespace Tests\Feature\Portal;

use App\Mail\ApplicantInvitationMail;
use App\Models\Applicant;
use App\Models\AuditEvent;
use App\Models\DocumentTemplate;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    public function test_hr_creates_applicant_with_documents_and_invitation(): void
    {
        Mail::fake();
        $hr = $this->actingAsHr();
        $template = DocumentTemplate::factory()->create();

        $this->post('/hr/applicants', [
            'first_name' => ' Maria ', 'last_name' => 'Garcia', 'email' => 'Maria@Example.com',
            'templates' => [$template->id], 'send_invitation' => '1',
        ])->assertRedirect();

        $applicant = Applicant::firstOrFail();
        $this->assertSame('Maria', $applicant->first_name);
        $this->assertSame('maria@example.com', $applicant->email);
        $this->assertSame($hr->id, $applicant->created_by);
        $this->assertCount(1, $applicant->documents);

        $invitation = $applicant->invitations()->firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $invitation->expires_at->getTimestamp(), 5);

        $code = null;
        Mail::assertQueued(ApplicantInvitationMail::class, function (ApplicantInvitationMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('maria@example.com');
        });

        // Only a keyed hash is stored, never the raw code.
        $this->assertNotSame($code, $invitation->token_hash);
        $this->assertSame(64, strlen($invitation->token_hash));
        foreach (DB::table('applicant_invitations')->get() as $row) {
            $this->assertStringNotContainsString(str_replace('-', '', $code), json_encode($row));
        }
        foreach (AuditEvent::all() as $event) {
            $json = json_encode($event->getAttributes());
            $this->assertStringNotContainsString($code, $json);
            $this->assertStringNotContainsString('Garcia', $json);
            $this->assertStringNotContainsString('maria@example.com', $json);
        }
    }

    public function test_invitation_mail_payload_is_encrypted_and_link_uses_fragment(): void
    {
        $this->assertContains(ShouldBeEncrypted::class, class_implements(ApplicantInvitationMail::class));

        Mail::fake();
        $this->actingAsHr();
        $this->post('/hr/applicants', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com', 'send_invitation' => '1']);

        Mail::assertQueued(ApplicantInvitationMail::class, function (ApplicantInvitationMail $mail) {
            $html = $mail->render();

            return str_contains($html, route('portal.access').'#'.$mail->code) && ! str_contains($html, '?code=');
        });
    }

    public function test_sending_marks_invitation_delivered(): void
    {
        $this->actingAsHr();
        $this->post('/hr/applicants', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com', 'send_invitation' => '1']);

        $invitation = Applicant::firstOrFail()->invitations()->firstOrFail();
        $this->assertSame('sent', $invitation->delivery_status);
        $this->assertNotNull($invitation->sent_at);
    }

    public function test_reissue_revokes_old_code_and_preserves_progress(): void
    {
        Mail::fake();
        $hr = $this->actingAsHr();
        [$applicant, $oldCode] = $this->applicantWithInvitation(hr: $hr);
        $doc = $this->assign($applicant, DocumentTemplate::factory()->onlineForm()->create());
        $doc->forceFill(['form_data' => ['saved' => 'yes']])->save();
        $applicant->forceFill(['consented_at' => now()])->save();

        $this->post("/hr/applicants/{$applicant->public_id}/invitations")->assertRedirect();

        $this->assertSame(2, $applicant->invitations()->count());
        $old = $applicant->invitations()->reorder()->oldest('id')->first();
        $this->assertNotNull($old->revoked_at);
        $this->assertSame('reissued', $old->revoke_reason);
        $this->assertSame(['saved' => 'yes'], $doc->fresh()->form_data);
        $this->assertNotNull($applicant->fresh()->consented_at);

        // Old code no longer works.
        $this->post('/portal/access', ['code' => $oldCode])->assertSessionHasErrors('code');
        $this->assertDatabaseHas('audit_events', ['event' => 'invitation.issued', 'applicant_ref' => $applicant->applicant_ref]);
    }

    public function test_hr_can_revoke_access(): void
    {
        $this->actingAsHr();
        [$applicant, $code] = $this->applicantWithInvitation();
        $invitation = $applicant->invitations()->first();

        $this->delete("/hr/applicants/{$applicant->public_id}/invitations/{$invitation->id}")->assertRedirect();

        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->post('/portal/access', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_changing_email_revokes_active_invitation(): void
    {
        $this->actingAsHr();
        [$applicant] = $this->applicantWithInvitation();

        $this->put("/hr/applicants/{$applicant->public_id}", [
            'first_name' => $applicant->first_name, 'last_name' => $applicant->last_name, 'email' => 'new@example.com',
        ])->assertRedirect();

        $this->assertSame('email_changed', $applicant->invitations()->first()->revoke_reason);
        $this->assertDatabaseHas('audit_events', ['event' => 'applicant.updated']);
        $this->assertSame(['fields' => 'email'], AuditEvent::where('event', 'applicant.updated')->first()->metadata);
    }

    public function test_hr_applicant_pages_require_authentication(): void
    {
        [$applicant] = $this->applicantWithInvitation();

        $this->get('/hr/applicants')->assertRedirect('/hr/login');
        $this->get("/hr/applicants/{$applicant->public_id}")->assertRedirect('/hr/login');
        $this->post("/hr/applicants/{$applicant->public_id}/invitations")->assertRedirect('/hr/login');
    }

    public function test_every_hr_user_can_see_every_applicant(): void
    {
        [$applicant] = $this->applicantWithInvitation(hr: $this->hrUser());
        $this->actingAsHr($this->hrUser());

        $this->get("/hr/applicants/{$applicant->public_id}")->assertOk()->assertSee($applicant->last_name);
        $this->get('/hr/applicants?q='.urlencode($applicant->last_name))->assertOk()->assertSee($applicant->email);
    }
}
