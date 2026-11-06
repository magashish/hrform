<?php

namespace Tests\Feature\Templates;

use App\Models\DocumentTemplate;
use Database\Seeders\DefaultTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'W4', 'name' => 'Form W-4', 'document_type_label' => 'W-4',
            'input_mode' => 'online_form', 'signature_mode' => 'none',
            'allowed_extensions' => ['pdf'], 'max_size_kb' => 5000,
            'required_by_default' => '1', 'auto_assign' => '0', 'active' => '1',
            'supporting_requirements' => "Photo ID (front)\nVoided cheque | optional",
        ];
    }

    public function test_default_templates_are_wet_signature(): void
    {
        $this->seed(DefaultTemplatesSeeder::class);
        $this->seed(DefaultTemplatesSeeder::class); // idempotent

        $this->assertSame(2, DocumentTemplate::count());
        foreach (['I9', 'SSA89'] as $code) {
            $t = DocumentTemplate::where('code', $code)->firstOrFail();
            $this->assertSame('wet_signature_upload', $t->signature_mode->value);
            $this->assertTrue($t->required_by_default);
        }
    }

    public function test_hr_creates_template_with_supporting_requirements(): void
    {
        $this->actingAsHr();

        $this->post('/hr/templates', $this->payload())->assertRedirect();

        $t = DocumentTemplate::where('code', 'W4')->firstOrFail();
        $this->assertSame([
            ['key' => 'photo_id_front', 'label' => 'Photo ID (front)', 'required' => true],
            ['key' => 'voided_cheque', 'label' => 'Voided cheque', 'required' => false],
        ], $t->supporting_requirements);
        $this->assertDatabaseHas('audit_events', ['event' => 'template.created']);
    }

    public function test_extensions_outside_the_global_allowlist_are_rejected(): void
    {
        $this->actingAsHr();

        $this->post('/hr/templates', $this->payload(['allowed_extensions' => ['pdf', 'exe']]))->assertSessionHasErrors('allowed_extensions.1');
    }

    public function test_electronic_signature_is_blocked_without_provider_and_approval(): void
    {
        $this->actingAsHr();
        $this->post('/hr/templates', $this->payload(['signature_mode' => 'provider_esign']))->assertSessionHasErrors('signature_mode');
        $this->assertSame(0, DocumentTemplate::count());

        // A provider alone is not enough: an approval must be recorded first.
        config(['portal.esign.provider' => 'fake', 'portal.esign.webhook_secret' => 'secret']);
        $this->post('/hr/templates', $this->payload())->assertRedirect();
        $t = DocumentTemplate::firstOrFail();
        $this->put("/hr/templates/{$t->id}", $this->payload(['signature_mode' => 'provider_esign']))->assertSessionHasErrors('signature_mode');

        $this->post("/hr/templates/{$t->id}/approvals", [
            'signature_mode' => 'provider_esign', 'authorized_by_name' => 'Jane Counsel (HR consultant)',
            'approval_reference' => 'Email 2026-11-01', 'authorized_at' => now()->toDateString(),
        ])->assertRedirect();

        $this->put("/hr/templates/{$t->id}", $this->payload(['signature_mode' => 'provider_esign']))->assertSessionHasNoErrors();
        $this->assertSame('provider_esign', $t->fresh()->signature_mode->value);
        $this->assertDatabaseHas('audit_events', ['event' => 'template.signature_approval_recorded']);
    }

    public function test_field_configuration_is_validated(): void
    {
        $this->actingAsHr();
        $t = DocumentTemplate::factory()->onlineForm()->create();

        $this->post("/hr/templates/{$t->id}/fields", ['field_key' => 'Bad Key', 'label' => 'X', 'type' => 'text'])->assertSessionHasErrors('field_key');
        $this->post("/hr/templates/{$t->id}/fields", ['field_key' => 'x', 'label' => 'X', 'type' => 'script'])->assertSessionHasErrors('type');
        $this->post("/hr/templates/{$t->id}/fields", ['field_key' => 'status', 'label' => 'Status', 'type' => 'select'])->assertSessionHasErrors('options');

        $this->post("/hr/templates/{$t->id}/fields", [
            'field_key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => "Single\n\nMarried\nSingle", 'required' => '1',
            'max_length' => '50', // irrelevant keys are dropped
        ])->assertRedirect();

        $field = $t->fields()->firstOrFail();
        $this->assertSame(['options' => ['Single', 'Married']], $field->config);

        $this->post("/hr/templates/{$t->id}/fields", ['field_key' => 'ssn', 'label' => 'SSN', 'type' => 'ssn'])->assertRedirect();
        $this->assertTrue($t->fields()->where('field_key', 'ssn')->first()->sensitive);

        $this->post("/hr/templates/{$t->id}/fields", ['field_key' => 'ssn', 'label' => 'Dup', 'type' => 'text'])->assertSessionHasErrors('field_key');
    }

    public function test_printable_form_upload(): void
    {
        $this->actingAsHr();
        $t = DocumentTemplate::factory()->wetSignature()->create();

        $this->post("/hr/templates/{$t->id}/print-asset", ['print_asset' => $this->png()])->assertSessionHasErrors('print_asset');
        $this->post("/hr/templates/{$t->id}/print-asset", ['print_asset' => $this->pdf('i-9.pdf')])->assertRedirect();

        $t->refresh();
        $this->assertSame('i-9.pdf', $t->print_asset_name);
        Storage::disk('documents')->assertExists($t->print_asset_path);
        $this->get("/hr/templates/{$t->id}/print-asset")->assertOk();
    }

    public function test_template_writes_require_password_confirmation(): void
    {
        $this->actingAsHrUnconfirmed();

        $this->post('/hr/templates', $this->payload())->assertRedirect('/hr/user/confirm-password');
        $this->assertSame(0, DocumentTemplate::count());
    }
}
