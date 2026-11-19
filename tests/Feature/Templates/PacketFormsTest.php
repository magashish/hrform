<?php

namespace Tests\Feature\Templates;

use App\Models\DocumentTemplate;
use Database\Seeders\DefaultTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

/**
 * Buffalo Valley pre-hire packet forms (2026-11-19): Emp Data, Ed Ver,
 * Emp Ver, Reference, User.
 */
class PacketFormsTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private function flatPdf(int $pages = 1, string $label = 'FORM'): string
    {
        $pdf = new \FPDF('P', 'pt', 'Letter');
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->Text(20, 40, "{$label} PAGE {$i}");
        }

        return $pdf->Output('S');
    }

    private function install(string $code, int $pages = 1): DocumentTemplate
    {
        $t = DocumentTemplate::where('code', $code)->firstOrFail();
        Storage::disk('documents')->put("templates/{$code}.bin", $this->flatPdf($pages, $code));
        $t->forceFill(['print_asset_path' => "templates/{$code}.bin", 'print_asset_name' => "{$code}.pdf"])->save();

        return $t->fresh();
    }

    private function pdfText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $bytes);
        $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');
        unlink($path);

        return $text;
    }

    private function referenceAnswers(array $overrides = []): array
    {
        return $overrides + [
            'ref1_name' => 'John Smith', 'ref1_phone' => '931-555-0101', 'ref1_years' => '7', 'ref1_relationship' => 'Employer', 'ref1_contact' => 'HR should call them',
            'ref2_name' => 'Ana Lopez', 'ref2_phone' => '615-555-0102', 'ref2_years' => '3', 'ref2_relationship' => 'Family', 'ref2_contact' => 'They will send a reference letter',
            'ref3_name' => 'Rob Jones', 'ref3_phone' => '731-555-0103', 'ref3_years' => '2', 'ref3_relationship' => 'Coworker', 'ref3_contact' => 'HR should call them',
        ];
    }

    public function test_packet_templates_are_configured_with_client_names_and_stages(): void
    {
        $this->seed(DefaultTemplatesSeeder::class);

        $expected = [
            'EMPDATA' => ['Emp Data', 'wet_signature_upload', 'initial'],
            'EDVER' => ['Ed Ver', 'wet_signature_upload', 'initial'],
            'EMPVER' => ['Emp Ver', 'wet_signature_upload', 'initial'],
            'REFERENCE' => ['Reference', 'none', 'initial'],
            'USERACCESS' => ['User', 'wet_signature_upload', 'day_one'],
            'I9' => ['I-9', 'wet_signature_upload', 'day_one'],
        ];
        foreach ($expected as $code => [$label, $signature, $stage]) {
            $t = DocumentTemplate::where('code', $code)->firstOrFail();
            $this->assertSame($label, $t->document_type_label, $code);
            $this->assertSame($signature, $t->signature_mode->value, $code);
            $this->assertSame($stage, $t->default_stage, $code);
            // Every packet form is pre-filled; the official I-9 is encrypted and cannot be.
            $code === 'I9' ? $this->assertNull($t->prefill_map) : $this->assertNotEmpty($t->prefill_map, $code);
        }
    }

    public function test_signed_forms_get_the_applicants_name_on_every_page_and_client_file_names(): void
    {
        $this->seed(DefaultTemplatesSeeder::class);
        $edver = $this->install('EDVER', pages: 2);
        [$applicant] = $this->applicantWithInvitation(['first_name' => 'Maria', 'last_name' => 'Garcia']);
        $doc = $this->assign($applicant, $edver);
        $this->signInApplicant($applicant);

        $this->get("/portal/documents/{$doc->id}")->assertSee('your name is already filled in');
        $text = $this->pdfText($this->get("/portal/documents/{$doc->id}/print")->assertOk()->getContent());
        $this->assertSame(2, substr_count($text, 'Maria Garcia'));

        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf('scan.pdf')]);
        $this->assertSame('Garcia, Maria Ed Ver.pdf', $doc->fresh()->currentVersion->generated_filename);
    }

    public function test_reference_form_is_completed_online_and_the_pdf_is_generated_on_submit(): void
    {
        $this->seed(DefaultTemplatesSeeder::class);
        $reference = $this->install('REFERENCE');
        [$applicant] = $this->applicantWithInvitation(['first_name' => 'Maria', 'last_name' => 'Garcia', 'email' => 'maria@example.com']);
        $doc = $this->assign($applicant, $reference);
        $this->signInApplicant($applicant);

        // No upload or signature step.
        $this->get("/portal/documents/{$doc->id}")->assertOk()->assertDontSee('Sign by hand and upload')->assertSee('Reference 3: full name');

        $this->put("/portal/documents/{$doc->id}/draft", $this->referenceAnswers() + ['next' => 'review'])->assertRedirect();
        $this->get("/portal/documents/{$doc->id}/review")->assertSee('No printing or signature is needed');
        $this->post("/portal/documents/{$doc->id}/supporting/reference_letters", ['supporting_file' => $this->pdf('letter.pdf')])->assertRedirect();
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertRedirect();

        $doc->refresh();
        $this->assertSame('submitted', $doc->status->value);
        $version = $doc->currentVersion;
        $this->assertNotNull($version);
        $this->assertSame('Garcia, Maria Reference.pdf', $version->generated_filename);
        $this->assertSame('system', $version->uploaded_by_type);

        $text = $this->pdfText(Storage::disk('documents')->get($version->storage_path));
        foreach (['REFERENCE PAGE 1', 'Maria Garcia', 'maria@example.com', 'John Smith', '931-555-0101', 'Ana Lopez', 'Rob Jones'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertDatabaseHas('audit_events', ['event' => 'document.generated']);

        // HR receives it like any other document, in the ZIP too.
        $this->flushSession();
        $this->actingAsHr();
        $this->get("/hr/applicants/{$applicant->public_id}/files/version/{$version->id}")->assertOk();
    }

    public function test_reference_rules_from_the_form_are_enforced(): void
    {
        $this->seed(DefaultTemplatesSeeder::class);
        [$applicant] = $this->applicantWithInvitation();
        $doc = $this->assign($applicant, $this->install('REFERENCE'));
        $this->signInApplicant($applicant);

        // Two family references.
        $this->put("/portal/documents/{$doc->id}/draft", $this->referenceAnswers(['ref3_relationship' => 'Family']));
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertSessionHasErrors('ref3_relationship');

        // Nobody known for 5+ years.
        $this->put("/portal/documents/{$doc->id}/draft", $this->referenceAnswers(['ref1_years' => '4']));
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1'])->assertSessionHasErrors('ref1_years');

        $this->assertSame(0, $doc->versions()->count());
        $this->assertNotSame('submitted', $doc->fresh()->status->value);
    }

    public function test_command_installs_client_pdfs_from_a_folder(): void
    {
        $this->hrUser();
        $dir = sys_get_temp_dir().'/client-pdfs-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/Last_Name_First_Name_User.pdf', $this->flatPdf(1, 'USER'));
        file_put_contents($dir.'/Last_Name_First_Name_Reference.pdf', $this->flatPdf(1, 'REF'));

        $this->artisan('portal:templates:apply-client-forms', ['--pdfs' => $dir])
            ->expectsOutputToContain('Installed printable form Last_Name_First_Name_User.pdf')
            ->expectsOutputToContain('Not found: Last_Name_First_Name_Emp_Data.pdf')
            ->assertSuccessful();

        $this->assertTrue(DocumentTemplate::where('code', 'USERACCESS')->first()->hasPrefill());
        $this->assertFalse(DocumentTemplate::where('code', 'EMPDATA')->first()->hasPrintAsset());

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
}
