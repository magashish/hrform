<?php

namespace Tests\Feature\Review;

use App\Mail\ApplicantNoticeMail;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\AuditEvent;
use App\Models\DocumentTemplate;
use App\Models\ExportArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;
use ZipArchive;

class ReviewAndExportTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    /**
     * @return array{0: Applicant, 1: ApplicantDocument}
     */
    private function submittedDocument(array $applicant = [], ?DocumentTemplate $template = null): array
    {
        [$a] = $this->applicantWithInvitation($applicant);
        $template ??= DocumentTemplate::factory()->wetSignature()->withSupporting([['key' => 'photo_id', 'label' => 'Photo ID', 'required' => true]])
            ->create(['document_type_label' => 'I-9', 'code' => 'I9-'.uniqid()]);
        $doc = $this->assign($a, $template);

        $this->signInApplicant($a);
        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf('signed.pdf', '% '.uniqid('', true))]);
        $this->post("/portal/documents/{$doc->id}/supporting/photo_id", ['supporting_file' => $this->png()]);
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1']);
        $this->flushSession();
        $this->assertSame('submitted', $doc->fresh()->status->value);

        return [$a->fresh(), $doc->fresh()];
    }

    public function test_hr_reviews_and_approves(): void
    {
        [$applicant, $doc] = $this->submittedDocument();
        $this->actingAsHr();

        $this->get("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}")->assertOk()->assertSee('Approve document');
        $this->assertSame('under_review', $doc->fresh()->status->value);

        $this->post("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}/approve")->assertRedirect();

        $this->assertSame('approved', $doc->fresh()->status->value);
        $this->assertSame('ready_for_download', $applicant->fresh()->status->value);
        $this->assertDatabaseHas('audit_events', ['event' => 'document.approved', 'applicant_ref' => $applicant->applicant_ref]);
    }

    public function test_correction_request_and_resubmission(): void
    {
        Mail::fake();
        [$applicant, $doc] = $this->submittedDocument();
        $hr = $this->actingAsHr();

        $this->post("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}/corrections", ['message' => ''])->assertSessionHasErrors('message');
        $this->post("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}/corrections", [
            'message' => 'The photo of page 2 is blurry, please re-upload.', 'scope' => 'signed_upload',
        ])->assertRedirect();

        $this->assertSame('correction_required', $doc->fresh()->status->value);
        $this->assertSame('correction_required', $applicant->fresh()->status->value);

        // Email says a correction is needed but does not include the message.
        Mail::assertQueued(ApplicantNoticeMail::class, function (ApplicantNoticeMail $m) use ($applicant) {
            return $m->hasTo($applicant->email) && ! str_contains($m->render(), 'blurry');
        });
        // Audit records the scope, not the message text.
        $this->assertStringNotContainsString('blurry', json_encode(AuditEvent::where('event', 'document.correction_requested')->first()->metadata));

        // Applicant sees the message and replaces the file.
        auth()->logout();
        $this->signInApplicant($applicant);
        $this->get("/portal/documents/{$doc->id}")->assertSee('blurry');
        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf('fixed.pdf')])->assertRedirect();
        $this->assertSame('correction_required', $doc->fresh()->status->value); // until resubmitted
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertRedirect();

        $doc->refresh();
        $this->assertSame('submitted', $doc->status->value);
        $this->assertSame(2, $doc->currentVersion->version_number);
        $this->assertSame(2, $doc->versions()->count());
        $this->assertSame('resolved', $doc->corrections()->first()->status);
        $this->assertSame(['template' => $doc->template->code, 'resubmission' => true], AuditEvent::where('event', 'document.submitted')->latest('id')->first()->metadata);
    }

    public function test_hr_downloads_are_authorized_scoped_and_audited(): void
    {
        [$applicant, $doc] = $this->submittedDocument();
        [$other] = $this->applicantWithInvitation();
        $version = $doc->currentVersion;

        // Guests cannot download.
        $this->get("/hr/applicants/{$applicant->public_id}/files/version/{$version->id}")->assertRedirect('/hr/login');

        $this->actingAsHr();
        $download = $this->get("/hr/applicants/{$applicant->public_id}/files/version/{$version->id}");
        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString($version->generated_filename, $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertSame('%PDF', substr($download->streamedContent(), 0, 4));

        $preview = $this->get("/hr/applicants/{$applicant->public_id}/files/version/{$version->id}?inline=1");
        $this->assertStringContainsString('inline', $preview->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $preview->headers->get('Content-Security-Policy'));

        // Same file through another applicant's URL: 404.
        $this->get("/hr/applicants/{$other->public_id}/files/version/{$version->id}")->assertNotFound();
        $this->get("/hr/applicants/{$other->public_id}/documents/{$doc->id}")->assertNotFound();

        $this->assertSame(2, AuditEvent::where('event', 'file.downloaded')->count());
    }

    public function test_sensitive_fields_are_masked_and_reveal_is_audited(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $template = DocumentTemplate::factory()->onlineForm()->create();
        $template->fields()->create(['field_key' => 'ssn', 'label' => 'SSN', 'type' => 'ssn', 'required' => true, 'sensitive' => true]);
        $doc = $this->assign($applicant, $template);
        $doc->forceFill(['form_data' => ['ssn' => '123-45-6789']])->save();

        $hr = $this->hrUser();
        $this->actingAsHrUnconfirmed($hr);
        $this->get("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}")->assertSee('***-**-6789')->assertDontSee('123-45-6789');

        // Reveal requires a fresh password confirmation.
        $this->post("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}/reveal", ['field' => 'ssn'])->assertRedirect('/hr/user/confirm-password');

        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->post("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}/reveal", ['field' => 'ssn'])->assertRedirect();
        $this->get("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}")->assertSee('123-45-6789');
        // Only for that one page view.
        $this->get("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}")->assertDontSee('123-45-6789');

        $event = AuditEvent::where('event', 'sensitive_field.revealed')->firstOrFail();
        $this->assertSame((string) $hr->id, $event->actor_id);
        $this->assertStringNotContainsString('6789', json_encode($event->getAttributes()));
    }

    public function test_zip_contains_only_this_applicants_files_with_safe_names(): void
    {
        [$applicant, $doc] = $this->submittedDocument(['first_name' => 'Ana', 'last_name' => '../../Evil']);
        [$other, $otherDoc] = $this->submittedDocument(['first_name' => 'Other', 'last_name' => 'Person']);
        $form = DocumentTemplate::factory()->onlineForm()->create(['document_type_label' => 'W-4']);
        $form->fields()->create(['field_key' => 'ssn', 'label' => 'SSN', 'type' => 'ssn']);
        $formDoc = $this->assign($applicant, $form);
        $formDoc->forceFill(['form_data' => ['ssn' => '123-45-6789']])->save();

        $hr = $this->actingAsHr();
        $this->post("/hr/applicants/{$applicant->public_id}/exports")->assertRedirect();

        $export = ExportArchive::firstOrFail();
        $this->assertSame('ready', $export->status);
        $this->assertSame(3, $export->file_count);

        $path = Storage::disk('exports')->path($export->storage_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        foreach ($names as $name) {
            $this->assertStringNotContainsString('..', $name);
            $this->assertStringStartsNotWith('/', $name);
            $this->assertStringNotContainsString('Other', $name);
            $this->assertStringNotContainsString('Person', $name);
            $this->assertMatchesRegularExpression('#^Evil_Ana_Packet_\d{8}/[A-Za-z0-9_./\-]+$#', $name);
        }
        $this->assertContains('Evil_Ana_Packet_'.now()->format('Ymd').'/MANIFEST.txt', $names);
        $this->assertCount(4, $names);
        $manifest = $zip->getFromName('Evil_Ana_Packet_'.now()->format('Ymd').'/MANIFEST.txt');
        $this->assertStringContainsString($doc->currentVersion->sha256, $manifest);
        $this->assertStringNotContainsString($otherDoc->currentVersion->sha256, $manifest);
        $zip->close();

        // Download: requester only, then expiry.
        $this->get("/hr/applicants/{$applicant->public_id}/exports/{$export->id}")->assertOk();
        $this->assertDatabaseHas('audit_events', ['event' => 'export.downloaded']);

        $this->actingAsHr($this->hrUser());
        $this->get("/hr/applicants/{$applicant->public_id}/exports/{$export->id}")->assertNotFound();
        $this->actingAsHr($hr);
        $this->get("/hr/applicants/{$other->public_id}/exports/{$export->id}")->assertNotFound();

        $this->travel(61)->minutes();
        $this->get("/hr/applicants/{$applicant->public_id}/exports/{$export->id}")->assertStatus(410);
    }

    public function test_mark_transferred_starts_retention_and_closes_access(): void
    {
        [$applicant, $doc] = $this->submittedDocument();
        $hr = $this->actingAsHr();
        $this->post("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}/approve");

        $this->post("/hr/applicants/{$applicant->public_id}/transfer", [])->assertSessionHasErrors('confirm_transfer');
        $this->post("/hr/applicants/{$applicant->public_id}/transfer", ['confirm_transfer' => '1'])->assertRedirect();

        $applicant->refresh();
        $this->assertSame('transferred', $applicant->status->value);
        $this->assertSame($hr->id, $applicant->transferred_by);
        $this->assertEqualsWithDelta(now()->addDays(60)->getTimestamp(), $applicant->deletion_due_at->getTimestamp(), 5);
        $this->assertSame('complete', $doc->fresh()->status->value);
        $this->assertSame(0, $applicant->invitations()->whereNull('revoked_at')->count());
        $this->assertSame(['retention_days' => 60], AuditEvent::where('event', 'applicant.transferred')->first()->metadata);
    }

    public function test_hr_can_delete_an_individual_file(): void
    {
        [$applicant, $doc] = $this->submittedDocument();
        $file = $doc->currentSupportingFiles()->first();
        $this->actingAsHr();

        $this->delete("/hr/applicants/{$applicant->public_id}/files/supporting/{$file->id}")->assertRedirect();

        Storage::disk('documents')->assertMissing($file->storage_path);
        $this->assertDatabaseMissing('supporting_files', ['id' => $file->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'file.deleted']);
    }

    public function test_assign_and_unassign_documents(): void
    {
        $hr = $this->actingAsHr();
        [$applicant] = $this->applicantWithInvitation(hr: $hr);
        $template = DocumentTemplate::factory()->create();

        $this->post("/hr/applicants/{$applicant->public_id}/documents", ['template_id' => $template->id, 'required' => '1'])->assertRedirect();
        $doc = $applicant->documents()->firstOrFail();
        $this->assertTrue($doc->required);

        $this->delete("/hr/applicants/{$applicant->public_id}/documents/{$doc->id}")->assertRedirect();
        $this->assertSame(0, $applicant->documents()->count());
    }
}
