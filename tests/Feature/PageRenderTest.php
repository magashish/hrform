<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\ExportArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

/**
 * Renders every page with realistic data and checks it is CSP-clean
 * (no inline scripts or styles) and free of server errors.
 */
class PageRenderTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private function assertCspClean(string $html, string $url): void
    {
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, $url);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $html, $url);
        $this->assertStringNotContainsString('<style', $html, $url);
    }

    public function test_all_pages_render(): void
    {
        $hr = $this->hrUser();
        [$applicant] = $this->applicantWithInvitation(hr: $hr);
        $form = DocumentTemplate::factory()->onlineForm()->create(['name' => 'Form W-4']);
        foreach (['text', 'textarea', 'email', 'phone', 'date', 'number', 'checkbox', 'ssn'] as $i => $type) {
            $form->fields()->create(['field_key' => "f_{$type}", 'label' => ucfirst($type), 'type' => $type, 'sort_order' => $i]);
        }
        $form->fields()->create(['field_key' => 'pick', 'label' => 'Pick', 'type' => 'select', 'config' => ['options' => ['A', 'B']]]);
        $form->fields()->create(['field_key' => 'radio', 'label' => 'Radio', 'type' => 'radio', 'config' => ['options' => ['A', 'B']]]);
        $form->fields()->create(['field_key' => 'att', 'label' => 'Agree', 'type' => 'attestation', 'config' => ['statement' => 'I certify']]);
        $wet = DocumentTemplate::factory()->wetSignature()->withSupporting([['key' => 'id', 'label' => 'ID', 'required' => true]])->create();
        $ack = DocumentTemplate::factory()->acknowledgement()->create();
        $formDoc = $this->assign($applicant, $form);
        $wetDoc = $this->assign($applicant, $wet);
        $ackDoc = $this->assign($applicant, $ack);

        // Applicant pages.
        $this->get('/portal/access')->assertOk();
        $this->signInApplicant($applicant);
        $this->post("/portal/documents/{$wetDoc->id}/upload", ['file' => $this->pdf()]);
        $portal = ['/portal', "/portal/documents/{$formDoc->id}", "/portal/documents/{$wetDoc->id}", "/portal/documents/{$ackDoc->id}",
            "/portal/documents/{$formDoc->id}/review", "/portal/documents/{$wetDoc->id}/review", '/portal/complete'];
        foreach ($portal as $url) {
            $this->assertCspClean($this->get($url)->assertOk()->getContent(), $url);
        }
        $this->flushSession();

        // HR pages.
        $this->actingAsHr($hr);
        ExportArchive::create(['applicant_id' => $applicant->id, 'requested_by' => $hr->id, 'status' => 'pending']);
        $hrPages = ['/', '/hr', '/hr/applicants', '/hr/applicants?status=invited&q=a', '/hr/applicants/create',
            "/hr/applicants/{$applicant->public_id}", "/hr/applicants/{$applicant->public_id}/edit",
            "/hr/applicants/{$applicant->public_id}/documents/{$formDoc->id}", "/hr/applicants/{$applicant->public_id}/documents/{$wetDoc->id}",
            '/hr/templates', '/hr/templates/create', "/hr/templates/{$form->id}/edit", "/hr/templates/{$wet->id}/edit",
            '/hr/consent', '/hr/consent/create', '/hr/consent/'.$this->activeConsent()->id,
            '/hr/settings', '/hr/system', '/hr/users', '/hr/users/create', '/hr/security/two-factor'];
        foreach ($hrPages as $url) {
            $this->assertCspClean($this->get($url)->assertOk()->getContent(), $url);
        }
    }
}
