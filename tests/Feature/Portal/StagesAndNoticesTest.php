<?php

namespace Tests\Feature\Portal;

use App\Mail\ApplicantInvitationMail;
use App\Mail\ApplicantNoticeMail;
use App\Models\Applicant;
use App\Models\ConsentVersion;
use App\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

/**
 * Client request (2026-11): choose when each form is needed (before screening,
 * after screening clears, day 1), and show the Contingent Job Offer and the
 * FCRA summary of rights before any paperwork.
 */
class StagesAndNoticesTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private function notice(string $key, string $body = 'Notice text', array $extra = []): ConsentVersion
    {
        return ConsentVersion::factory()->active()->create(['notice_key' => $key, 'version' => $key.'-v1', 'title' => ucfirst($key), 'body' => $body] + $extra);
    }

    public function test_hr_chooses_a_stage_per_document_when_creating_an_applicant(): void
    {
        Mail::fake();
        $this->actingAsHr();
        $now = DocumentTemplate::factory()->create(['name' => 'Emp Data form', 'default_stage' => 'initial']);
        $later = DocumentTemplate::factory()->create(['name' => 'Reference form', 'default_stage' => 'initial']);
        $day1 = DocumentTemplate::factory()->create(['name' => 'User form', 'default_stage' => 'day_one']);

        $this->post('/hr/applicants', [
            'first_name' => 'Maria', 'last_name' => 'Garcia', 'email' => 'm@example.com', 'position_title' => 'Investigator',
            'templates' => [$now->id, $later->id, $day1->id],
            'stages' => [$now->id => 'initial', $later->id => 'post_screening', $day1->id => 'day_one'],
            'send_invitation' => '1',
        ])->assertRedirect();

        $applicant = Applicant::firstOrFail();
        $this->assertSame('Investigator', $applicant->position_title);
        $docs = $applicant->documents()->with('template')->get()->keyBy(fn ($d) => $d->template->name);
        $this->assertTrue($docs['Emp Data form']->isReleased());
        $this->assertSame('post_screening', $docs['Reference form']->stage);
        $this->assertFalse($docs['Reference form']->isReleased());
        $this->assertSame('day_one', $docs['User form']->stage);
        $this->assertFalse($docs['User form']->isReleased());

        $this->get("/hr/applicants/{$applicant->public_id}")->assertOk()
            ->assertSee('After screening clears')->assertSee('Release to applicant')->assertSee('not yet visible to applicant');
    }

    public function test_applicant_sees_only_released_documents_and_cannot_open_held_ones(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $released = $this->assign($applicant, DocumentTemplate::factory()->create(['name' => 'Emp Data form']));
        $held = $this->assign($applicant, DocumentTemplate::factory()->create(['name' => 'Reference form']), stage: 'post_screening');
        $this->signInApplicant($applicant);

        $this->get('/portal')->assertOk()->assertSee('Emp Data form')->assertDontSee('Reference form')
            ->assertSee('HR will send you more documents later');
        $this->get("/portal/documents/{$held->id}")->assertNotFound();
        $this->post("/portal/documents/{$held->id}/upload", ['file' => $this->pdf()])->assertNotFound();

        // Completing everything released does not complete the packet while documents are held.
        $this->post("/portal/documents/{$released->id}/upload", ['file' => $this->pdf()]);
        $this->post("/portal/documents/{$released->id}/submit", ['confirm' => '1'])->assertRedirect('/portal/complete');
        $applicant->refresh();
        $this->assertSame('submitted', $applicant->status->value);
        $this->assertNull($applicant->completed_at);
    }

    public function test_releasing_a_stage_shows_the_documents_and_emails_the_applicant(): void
    {
        Mail::fake();
        [$applicant] = $this->applicantWithInvitation();
        $held = $this->assign($applicant, DocumentTemplate::factory()->create(['name' => 'Reference form']), stage: 'post_screening');
        $this->assign($applicant, DocumentTemplate::factory()->create(), stage: 'day_one');
        $this->actingAsHr();

        $this->post("/hr/applicants/{$applicant->public_id}/release", ['stage' => 'nonsense'])->assertSessionHasErrors('stage');
        $this->post("/hr/applicants/{$applicant->public_id}/release", ['stage' => 'post_screening'])->assertRedirect()
            ->assertSessionHas('status', 'Released 1 document(s). The applicant has been emailed.');

        $this->assertTrue($held->fresh()->isReleased());
        $this->assertSame(1, $applicant->documents()->whereNull('released_at')->count()); // day 1 still held
        Mail::assertQueued(ApplicantNoticeMail::class, fn ($m) => $m->hasTo($applicant->email) && $m->subjectLine === 'More onboarding documents are ready for you');
        $this->assertDatabaseHas('audit_events', ['event' => 'documents.released', 'applicant_ref' => $applicant->applicant_ref]);
    }

    public function test_release_after_invitation_expired_sends_a_new_invitation(): void
    {
        Mail::fake();
        [$applicant] = $this->applicantWithInvitation();
        $applicant->invitations()->update(['expires_at' => now()->subDay()]);
        $this->assign($applicant, DocumentTemplate::factory()->create(), stage: 'post_screening');
        $this->actingAsHr();

        $this->post("/hr/applicants/{$applicant->public_id}/release", ['stage' => 'post_screening'])->assertRedirect();

        Mail::assertQueued(ApplicantInvitationMail::class);
        $this->assertSame(1, $applicant->invitations()->whereNull('revoked_at')->where('expires_at', '>', now())->count());
    }

    public function test_hr_can_add_a_document_later_held_or_available_now(): void
    {
        $hr = $this->actingAsHr();
        [$applicant] = $this->applicantWithInvitation(hr: $hr);
        $t1 = DocumentTemplate::factory()->create();
        $t2 = DocumentTemplate::factory()->create();

        $this->post("/hr/applicants/{$applicant->public_id}/documents", ['template_id' => $t1->id, 'required' => '1', 'stage' => 'day_one'])->assertRedirect();
        $this->post("/hr/applicants/{$applicant->public_id}/documents", ['template_id' => $t2->id, 'required' => '1', 'stage' => 'day_one', 'release_now' => '1'])->assertRedirect();

        $this->assertFalse($applicant->documents()->where('document_template_id', $t1->id)->first()->isReleased());
        $this->assertTrue($applicant->documents()->where('document_template_id', $t2->id)->first()->isReleased());
    }

    public function test_contingent_offer_and_fcra_summary_come_first_in_order(): void
    {
        Storage::disk('documents')->put('notices/fcra.bin', '%PDF-1.4 summary');
        $this->notice('disclosure', 'Privacy disclosure text');
        $fcra = $this->notice('fcra_summary', 'A Summary of Your Rights Under the FCRA', ['attachment_path' => 'notices/fcra.bin', 'attachment_name' => 'fcra-summary.pdf']);
        $offer = $this->notice('contingent_offer', 'Dear {first_name}, we are pleased to offer you the position of {position_title}, contingent on <b>screening</b>.');
        [$applicant] = $this->applicantWithInvitation(['first_name' => 'Maria', 'position_title' => 'Investigator']);
        $this->signInApplicant($applicant, consented: false);

        // 1. Contingent offer, personalised and escaped.
        $this->get('/portal')->assertRedirect('/portal/consent');
        $this->get('/portal/consent')->assertOk()->assertSee('step 1 of 3')
            ->assertSee('Dear Maria, we are pleased to offer you the position of Investigator', false)
            ->assertSee('&lt;b&gt;screening&lt;/b&gt;', false);
        $this->post('/portal/consent', ['version_id' => $fcra->id, 'acknowledge' => '1'])->assertRedirect('/portal/consent'); // wrong order refused
        $this->post('/portal/consent', ['version_id' => $offer->id, 'acknowledge' => '1'])->assertRedirect('/portal/consent');

        // 2. FCRA summary, with its PDF.
        $this->get('/portal/consent')->assertSee('step 2 of 3')->assertSee('Summary of Your Rights')->assertSee('Open the full document (PDF)');
        $this->get("/portal/notices/{$fcra->id}/attachment")->assertOk();
        $this->get('/portal')->assertRedirect('/portal/consent'); // forms still locked
        $this->post('/portal/consent', ['version_id' => $fcra->id, 'acknowledge' => '1']);

        // 3. Disclosure, then the forms.
        $this->get('/portal/consent')->assertSee('step 3 of 3');
        $this->assertNull($applicant->fresh()->consented_at);
        $disclosure = ConsentVersion::current('disclosure');
        $this->post('/portal/consent', ['version_id' => $disclosure->id, 'acknowledge' => '1'])->assertRedirect('/portal');

        $this->assertNotNull($applicant->fresh()->consented_at);
        $this->assertSame(3, $applicant->consents()->count());
        $this->get('/portal')->assertOk()->assertSee($offer->title)->assertSee($fcra->title);
        $this->get("/portal/notices/{$offer->id}")->assertOk()->assertSee('Investigator');
    }

    public function test_applicant_cannot_read_notices_they_have_not_received(): void
    {
        $other = ConsentVersion::factory()->create(['notice_key' => 'contingent_offer', 'body' => 'Someone else']);
        [$applicant] = $this->applicantWithInvitation();
        $this->signInApplicant($applicant);

        $this->get("/portal/notices/{$other->id}")->assertNotFound();
        $this->get("/portal/notices/{$other->id}/attachment")->assertNotFound();
    }

    public function test_hr_manages_versions_per_notice_type(): void
    {
        $this->actingAsHr();

        $this->post('/hr/consent', ['notice_key' => 'fcra_summary', 'version' => '2023', 'title' => 'FCRA summary', 'body' => 'Text', 'activate' => '1',
            'attachment' => $this->pdf('summary-of-rights.pdf')])->assertRedirect('/hr/consent');
        $this->post('/hr/consent', ['notice_key' => 'contingent_offer', 'version' => '2023', 'title' => 'Offer', 'body' => 'Text', 'activate' => '1'])->assertRedirect();
        $this->post('/hr/consent', ['notice_key' => 'fcra_summary', 'version' => '2023', 'title' => 'Dup', 'body' => 'x'])->assertSessionHasErrors('version');
        $this->post('/hr/consent', ['notice_key' => 'made_up', 'version' => 'x', 'title' => 'x', 'body' => 'x'])->assertSessionHasErrors('notice_key');

        // Activating a new FCRA version leaves the contingent offer active.
        $this->post('/hr/consent', ['notice_key' => 'fcra_summary', 'version' => '2024', 'title' => 'FCRA summary', 'body' => 'New', 'activate' => '1'])->assertRedirect();
        $this->assertSame('2024', ConsentVersion::current('fcra_summary')->version);
        $this->assertSame('2023', ConsentVersion::current('contingent_offer')->version);
        $this->assertSame('summary-of-rights.pdf', ConsentVersion::where('notice_key', 'fcra_summary')->where('version', '2023')->first()->attachment_name);

        $this->get('/hr/consent')->assertOk()->assertSee('Contingent job offer')->assertSee('Summary of your rights under the Fair Credit Reporting Act');
    }
}
