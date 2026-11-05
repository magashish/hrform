<?php

namespace Tests\Feature\Documents;

use App\Events\DocumentSubmitted;
use App\Models\Applicant;
use App\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private function formTemplate(): DocumentTemplate
    {
        $t = DocumentTemplate::factory()->onlineForm()->create(['code' => 'W4', 'document_type_label' => 'W-4']);
        $t->fields()->createMany([
            ['field_key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'sort_order' => 1],
            ['field_key' => 'ssn', 'label' => 'SSN', 'type' => 'ssn', 'required' => true, 'sensitive' => true, 'sort_order' => 2],
            ['field_key' => 'filing', 'label' => 'Filing status', 'type' => 'select', 'required' => true, 'config' => ['options' => ['Single', 'Married']], 'sort_order' => 3],
            ['field_key' => 'agree', 'label' => 'I agree', 'type' => 'attestation', 'required' => true, 'config' => ['statement' => 'I certify...'], 'sort_order' => 4],
        ]);

        return $t;
    }

    public function test_save_draft_resume_and_submit_online_form(): void
    {
        Event::fake([DocumentSubmitted::class]);
        [$applicant] = $this->applicantWithInvitation();
        $doc = $this->assign($applicant, $this->formTemplate());
        $this->signInApplicant($applicant);

        $this->get("/portal/documents/{$doc->id}")->assertOk()->assertSee('Full name');

        // Partial draft is allowed.
        $this->put("/portal/documents/{$doc->id}/draft", ['full_name' => 'Pat Doe', 'ssn' => '123-45-6789'])
            ->assertRedirect("/portal/documents/{$doc->id}");
        $this->assertSame('in_progress', $doc->fresh()->status->value);

        // Stored encrypted: the raw column does not contain the SSN.
        $raw = DB::table('applicant_documents')->where('id', $doc->id)->value('form_data');
        $this->assertStringNotContainsString('123-45-6789', $raw);
        $this->assertStringNotContainsString('Pat Doe', $raw);

        // Resume: values are shown again (SSN input is a password field).
        $this->get("/portal/documents/{$doc->id}")->assertSee('Pat Doe');

        // Type rules still apply to drafts; unknown keys are ignored.
        $this->put("/portal/documents/{$doc->id}/draft", ['filing' => 'Hacker', 'evil' => 'x'])->assertSessionHasErrors('filing');

        // Submit requires everything.
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertSessionHasErrors();
        $this->assertSame('in_progress', $doc->fresh()->status->value);

        $this->put("/portal/documents/{$doc->id}/draft", [
            'full_name' => 'Pat Doe', 'ssn' => '123456789', 'filing' => 'Single', 'agree' => '1', 'next' => 'review',
        ])->assertRedirect("/portal/documents/{$doc->id}/review");
        $this->assertSame('123-45-6789', $doc->fresh()->form_data['ssn']);
        $this->assertArrayNotHasKey('evil', $doc->fresh()->form_data);

        $this->get("/portal/documents/{$doc->id}/review")->assertOk()->assertSee('***-**-6789')->assertDontSee('123-45-6789');

        $this->post("/portal/documents/{$doc->id}/submit", [])->assertSessionHasErrors('confirm');
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertRedirect('/portal/complete');

        $this->assertSame('submitted', $doc->fresh()->status->value);
        $this->assertSame('submitted', $applicant->fresh()->status->value);
        $this->assertNotNull($applicant->fresh()->completed_at);
        Event::assertDispatched(DocumentSubmitted::class);

        // Locked after submission.
        $this->put("/portal/documents/{$doc->id}/draft", ['full_name' => 'Changed'])->assertSessionHasErrors('document');
    }

    public function test_wet_signature_upload_flow(): void
    {
        [$applicant] = $this->applicantWithInvitation(['first_name' => 'José', 'last_name' => "O'Brien / Smith"]);
        $template = DocumentTemplate::factory()->wetSignature()->create(['code' => 'I9', 'document_type_label' => 'I-9']);
        Storage::disk('documents')->put('templates/blank.bin', '%PDF-1.4 blank');
        $template->forceFill(['print_asset_path' => 'templates/blank.bin', 'print_asset_name' => 'i-9.pdf'])->save();
        $doc = $this->assign($applicant, $template);
        $this->signInApplicant($applicant);

        $this->get("/portal/documents/{$doc->id}")->assertOk()->assertSee('sign it by hand in ink', false);

        $print = $this->get("/portal/documents/{$doc->id}/print");
        $print->assertOk();
        $this->assertStringContainsString('attachment', $print->headers->get('Content-Disposition'));

        // Cannot submit before uploading.
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertSessionHasErrors('submit');

        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf('my scan (final).pdf')])
            ->assertRedirect("/portal/documents/{$doc->id}");

        $version = $doc->fresh()->currentVersion;
        $this->assertSame(1, $version->version_number);
        $this->assertSame('my scan (final).pdf', $version->original_filename);
        $this->assertMatchesRegularExpression('/^OBrien-Smith_Jose_I-9_\d{8}\.pdf$/', $version->generated_filename);
        $this->assertStringNotContainsString('my scan', $version->storage_path);
        $this->assertStringEndsWith('.bin', $version->storage_path);
        $this->assertSame(64, strlen($version->sha256));
        $this->assertSame('not_scanned', $version->scan_status->value);
        Storage::disk('documents')->assertExists($version->storage_path);

        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertRedirect('/portal/complete');
        $this->assertSame('submitted', $doc->fresh()->status->value);
    }

    public function test_required_supporting_files_must_be_uploaded(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $template = DocumentTemplate::factory()->withSupporting([
            ['key' => 'photo_id', 'label' => 'Photo ID', 'required' => true],
            ['key' => 'extra', 'label' => 'Extra', 'required' => false],
        ])->create();
        $doc = $this->assign($applicant, $template);
        $this->signInApplicant($applicant);

        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf()]);
        $this->assertSame('awaiting_supporting_files', $doc->fresh()->status->value);
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertSessionHasErrors('submit');

        $this->post("/portal/documents/{$doc->id}/supporting/photo_id", ['supporting_file' => $this->png()])
            ->assertRedirect("/portal/documents/{$doc->id}");
        $this->post("/portal/documents/{$doc->id}/supporting/unknown_key", ['supporting_file' => $this->png()])->assertNotFound();

        $file = $doc->fresh()->currentSupportingFiles()->first();
        $this->assertSame('photo_id', $file->requirement_key);
        $this->assertStringContainsString('Photo-ID', $file->generated_filename);

        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertRedirect();
        $this->assertSame('submitted', $doc->fresh()->status->value);
    }

    public function test_optional_documents_do_not_block_completion(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $required = $this->assign($applicant, DocumentTemplate::factory()->create());
        $this->assign($applicant, DocumentTemplate::factory()->create(), required: false);
        $this->signInApplicant($applicant);

        $this->post("/portal/documents/{$required->id}/upload", ['file' => $this->pdf()]);
        $this->post("/portal/documents/{$required->id}/submit", ['confirm' => '1'])->assertRedirect('/portal/complete');

        $this->assertSame('submitted', $applicant->fresh()->status->value);
    }

    public function test_acknowledgement_documents(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $doc = $this->assign($applicant, DocumentTemplate::factory()->acknowledgement()->create(['instructions' => 'Handbook text']));
        $this->signInApplicant($applicant);

        $this->post("/portal/documents/{$doc->id}/acknowledge", [])->assertSessionHasErrors('acknowledge');
        $this->post("/portal/documents/{$doc->id}/acknowledge", ['acknowledge' => '1'])->assertRedirect();
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertRedirect();

        $this->assertNotNull($doc->fresh()->acknowledged_at);
        $this->assertSame('submitted', $doc->fresh()->status->value);
    }

    public function test_replacing_an_upload_keeps_version_history(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $doc = $this->assign($applicant, DocumentTemplate::factory()->create());
        $this->signInApplicant($applicant);

        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf('one.pdf')]);
        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf('two.pdf')]);

        $versions = $doc->fresh()->versions;
        $this->assertCount(2, $versions);
        $this->assertSame(2, $doc->fresh()->currentVersion->version_number);
        $this->assertNotNull($versions->firstWhere('version_number', 1)->superseded_at);
        $this->assertStringContainsString('_v2.pdf', $doc->fresh()->currentVersion->generated_filename);
        $this->assertDatabaseHas('audit_events', ['event' => 'document.uploaded']);
    }

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function badUploads(): array
    {
        return [
            'executable extension' => [fn () => UploadedFile::fake()->createWithContent('run.exe', 'MZ binary'), 'not accepted'],
            'php disguised as pdf' => [fn () => UploadedFile::fake()->createWithContent('doc.pdf', '<?php system($_GET["c"]); ?>'), 'does not match'],
            'html disguised as png' => [fn () => UploadedFile::fake()->createWithContent('img.png', '<html><script>alert(1)</script></html>'), 'does not match'],
            'svg' => [fn () => UploadedFile::fake()->createWithContent('img.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'not accepted'],
            'pdf with javascript' => [fn () => UploadedFile::fake()->createWithContent('js.pdf', "%PDF-1.4\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n%%EOF"), 'active content'],
            'too large' => [fn () => UploadedFile::fake()->create('big.pdf', 20000, 'application/pdf'), 'too large'],
            'empty' => [fn () => UploadedFile::fake()->createWithContent('empty.pdf', ''), 'empty'],
        ];
    }

    /**
     * @dataProvider badUploads
     */
    public function test_dangerous_or_invalid_uploads_are_rejected(callable $make, string $message): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $doc = $this->assign($applicant, DocumentTemplate::factory()->create(['max_size_kb' => 5000]));
        $this->signInApplicant($applicant);

        $response = $this->post("/portal/documents/{$doc->id}/upload", ['file' => $make()]);

        $response->assertSessionHasErrors('file');
        $this->assertStringContainsString($message, session('errors')->first('file'));
        $this->assertSame(0, $doc->versions()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_template_extension_allowlist_is_enforced(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $doc = $this->assign($applicant, DocumentTemplate::factory()->create(['allowed_extensions' => ['pdf']]));
        $this->signInApplicant($applicant);

        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->png()])->assertSessionHasErrors('file');
    }

    public function test_applicant_cannot_touch_another_applicants_documents(): void
    {
        [$alice] = $this->applicantWithInvitation();
        [$bob] = $this->applicantWithInvitation();
        $template = DocumentTemplate::factory()->create();
        $bobDoc = $this->assign($bob, $template);
        $this->assign($alice, $template);

        // Bob uploads a file.
        $this->signInApplicant($bob);
        $this->post("/portal/documents/{$bobDoc->id}/upload", ['file' => $this->pdf()]);
        $bobFile = $bobDoc->fresh()->currentVersion;

        // Alice signs in (fresh session) and tries Bob's IDs.
        $this->flushSession();
        $this->signInApplicant($alice);

        $this->get("/portal/documents/{$bobDoc->id}")->assertNotFound();
        $this->get("/portal/documents/{$bobDoc->id}/review")->assertNotFound();
        $this->put("/portal/documents/{$bobDoc->id}/draft", ['x' => 'y'])->assertNotFound();
        $this->post("/portal/documents/{$bobDoc->id}/upload", ['file' => $this->pdf()])->assertNotFound();
        $this->post("/portal/documents/{$bobDoc->id}/submit", ['confirm' => '1'])->assertNotFound();
        $this->get("/portal/documents/{$bobDoc->id}/files/version/{$bobFile->id}")->assertNotFound();
        $this->get('/portal/documents/not-a-uuid')->assertNotFound();

        // Alice's own document cannot be used to reach Bob's file either.
        $aliceDoc = Applicant::find($alice->id)->documents()->first();
        $this->get("/portal/documents/{$aliceDoc->id}/files/version/{$bobFile->id}")->assertNotFound();

        $this->assertSame(1, $bobDoc->versions()->count());
    }
}
