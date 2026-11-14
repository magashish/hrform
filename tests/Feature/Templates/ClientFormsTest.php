<?php

namespace Tests\Feature\Templates;

use App\Models\AuditEvent;
use App\Models\DocumentTemplate;
use App\Notifications\HrAlert;
use Database\Seeders\DefaultTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

/**
 * Client guidance (2026-11): I-9 and SSA-89 are signed by hand; the SSA-89 is
 * pre-filled with the applicant's name, DOB and SSN on the client's copy.
 */
class ClientFormsTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    /** A flat one-page US Letter PDF standing in for the client's SSA-89. */
    private function flatPdfBytes(string $label = 'SSA-89 TEST FORM'): string
    {
        $pdf = new \FPDF('P', 'pt', 'Letter');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(20, 40, $label);
        $pdf->Text(20, 100, 'Printed Name:          Date of Birth:          Social Security Number:');

        return $pdf->Output('S');
    }

    private function ssa89(): DocumentTemplate
    {
        $this->seed(DefaultTemplatesSeeder::class);
        $t = DocumentTemplate::where('code', 'SSA89')->firstOrFail();
        Storage::disk('documents')->put('templates/ssa89.bin', $this->flatPdfBytes());
        $t->forceFill(['print_asset_path' => 'templates/ssa89.bin', 'print_asset_name' => 'ssa-89.pdf'])->save();

        return $t->fresh();
    }

    private function pdfText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $bytes);
        $text = (string) shell_exec('pdftotext '.escapeshellarg($path).' - 2>/dev/null');
        unlink($path);

        return $text;
    }

    public function test_seeded_templates_follow_the_client_guidance(): void
    {
        $this->seed(DefaultTemplatesSeeder::class);

        $i9 = DocumentTemplate::where('code', 'I9')->firstOrFail();
        $this->assertSame('wet_signature_upload', $i9->signature_mode->value);
        $this->assertSame('01/20/25', $i9->form_edition);
        $this->assertSame('2027-05-31', $i9->form_expires_on->toDateString());
        $this->assertNull($i9->prefill_map);
        $this->assertStringContainsString('Do NOT type your name', $i9->instructions);

        $ssa = DocumentTemplate::where('code', 'SSA89')->firstOrFail();
        $this->assertSame('wet_signature_upload', $ssa->signature_mode->value);
        $this->assertSame('online_form', $ssa->input_mode->value);
        $this->assertSame(['printed_name', 'date_of_birth', 'ssn'], $ssa->fields->pluck('field_key')->all());
        $this->assertCount(3, $ssa->prefill_map);
        $this->assertFalse(collect($ssa->prefill_map)->contains(fn ($e) => str_contains($e['source'], 'sign')));
    }

    public function test_apply_client_forms_command_updates_existing_templates(): void
    {
        DocumentTemplate::factory()->create(['code' => 'SSA89', 'input_mode' => 'upload', 'signature_mode' => 'none', 'sort_order' => 5]);

        $this->artisan('portal:templates:apply-client-forms')->assertSuccessful();

        $ssa = DocumentTemplate::where('code', 'SSA89')->firstOrFail();
        $this->assertSame('online_form', $ssa->input_mode->value);
        $this->assertSame('wet_signature_upload', $ssa->signature_mode->value);
        $this->assertSame(5, $ssa->sort_order); // HR ordering kept
        $this->assertCount(3, $ssa->fields);
        $this->assertNotNull(DocumentTemplate::where('code', 'I9')->first());

        // Idempotent.
        $this->artisan('portal:templates:apply-client-forms')->assertSuccessful();
        $this->assertCount(3, $ssa->fresh()->fields);
    }

    public function test_applicant_gets_a_prefilled_ssa89_then_uploads_the_signed_copy(): void
    {
        $template = $this->ssa89();
        [$applicant] = $this->applicantWithInvitation(['first_name' => 'Maria', 'last_name' => 'Garcia']);
        $doc = $this->assign($applicant, $template);
        $this->signInApplicant($applicant);

        // The name field starts with the applicant's name; the download is offered only once the form is complete.
        $this->get("/portal/documents/{$doc->id}")->assertOk()->assertSee('value="Maria Garcia"', false)
            ->assertSee('Complete and save your information above');
        $this->get("/portal/documents/{$doc->id}/print")->assertRedirect("/portal/documents/{$doc->id}");

        $this->put("/portal/documents/{$doc->id}/draft", [
            'printed_name' => 'María J. García', 'date_of_birth' => '1990-01-15', 'ssn' => '123456789',
        ])->assertRedirect();
        $this->get("/portal/documents/{$doc->id}")->assertSee('Download your pre-filled form');

        $response = $this->get("/portal/documents/{$doc->id}/print");
        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $text = $this->pdfText($response->getContent());
        $this->assertStringContainsString('SSA-89 TEST FORM', $text);       // original form kept
        $this->assertStringContainsString('Mar', $text);                    // transliterated name
        $this->assertStringContainsString('01/15/1990', $text);             // US date format
        $this->assertStringContainsString('123-45-6789', $text);

        // Never stored: only the template's own PDF is on disk.
        $this->assertSame(['templates/ssa89.bin'], Storage::disk('documents')->allFiles());
        $event = AuditEvent::where('event', 'document.prefilled_downloaded')->firstOrFail();
        $this->assertStringNotContainsString('6789', json_encode($event->getAttributes()));

        // Then the usual wet-signature upload and submit.
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertSessionHasErrors('submit');
        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf('signed-ssa89.pdf')])->assertRedirect();
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertRedirect();
        $this->assertSame('submitted', $doc->fresh()->status->value);
    }

    public function test_another_applicant_cannot_download_someone_elses_prefilled_form(): void
    {
        $template = $this->ssa89();
        [$owner] = $this->applicantWithInvitation();
        [$other] = $this->applicantWithInvitation();
        $doc = $this->assign($owner, $template);
        $doc->forceFill(['form_data' => ['printed_name' => 'Owner', 'date_of_birth' => '1990-01-01', 'ssn' => '123-45-6789']])->save();

        $this->signInApplicant($other);
        $this->get("/portal/documents/{$doc->id}/print")->assertNotFound();
    }

    public function test_hr_can_preview_alignment_with_sample_data(): void
    {
        $template = $this->ssa89();
        $this->actingAsHr();

        $response = $this->get("/hr/templates/{$template->id}/prefill-preview");
        $response->assertOk();
        $text = $this->pdfText($response->getContent());
        $this->assertStringContainsString('SAMPLE', $text);
        $this->assertStringContainsString('000-00-0000', $text);
    }

    public function test_prefill_map_is_validated(): void
    {
        $template = $this->ssa89();
        $this->actingAsHr();
        $base = [
            'code' => 'SSA89', 'name' => $template->name, 'document_type_label' => 'SSA-89',
            'input_mode' => 'online_form', 'signature_mode' => 'wet_signature_upload',
            'allowed_extensions' => ['pdf'], 'max_size_kb' => 5000, 'active' => '1',
        ];

        foreach ([
            'not json',
            '[{"page":1,"x":1,"y":1,"source":"field:unknown_key"}]',
            '[{"page":1,"x":1,"y":1,"source":"field:ssn","script":"x"}]',
            '[{"page":1,"x":"a","y":1,"source":"field:ssn"}]',
            '[{"page":1,"x":1,"y":1,"source":"field:date_of_birth","format":"%s"}]',
        ] as $bad) {
            $this->put("/hr/templates/{$template->id}", $base + ['prefill_map' => $bad])->assertSessionHasErrors('prefill_map');
        }

        $this->put("/hr/templates/{$template->id}", $base + ['prefill_map' => '[{"page":1,"x":30,"y":120,"source":"field:ssn"}]'])
            ->assertSessionHasNoErrors();
        $this->assertSame([['page' => 1, 'x' => 30, 'y' => 120, 'source' => 'field:ssn']], $template->fresh()->prefill_map);
    }

    public function test_encrypted_pdfs_cannot_be_used_for_prefill(): void
    {
        $template = $this->ssa89();
        $this->actingAsHr();

        // Minimal PDF whose trailer declares encryption, like the official USCIS I-9.
        $encrypted = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            ."3 0 obj << /Filter /Standard /V 2 /R 3 /O <00> /U <00> /P -4 >> endobj\n"
            ."trailer << /Root 1 0 R /Encrypt 3 0 R /Size 4 >>\n%%EOF\n";

        $this->post("/hr/templates/{$template->id}/print-asset", [
            'print_asset' => UploadedFile::fake()->createWithContent('i9.pdf', $encrypted),
        ])->assertSessionHasErrors('print_asset');

        // The previous, working form is kept.
        $this->assertSame('templates/ssa89.bin', $template->fresh()->print_asset_path);
    }

    public function test_hr_is_warned_before_a_form_edition_expires(): void
    {
        Notification::fake();
        $this->seed(DefaultTemplatesSeeder::class);
        $hr = $this->hrUser();

        $this->artisan('portal:templates:check-expiry')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(now()->setDate(2027, 3, 15));
        $this->artisan('portal:templates:check-expiry')->assertSuccessful();
        Notification::assertSentTo($hr, HrAlert::class, fn (HrAlert $n) => str_contains($n->line, 'I-9') && str_contains($n->line, 'May 31, 2027'));

        $this->actingAsHr($hr);
        $this->get('/hr')->assertSee('expires on May 31, 2027');
        $this->get('/hr/templates')->assertSee('Expires May 31, 2027');
    }
}
