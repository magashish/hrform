<?php

namespace Tests\Feature\Templates;

use App\Models\DocumentTemplate;
use App\Models\SignatureTransaction;
use App\Services\Signatures\FakeSignatureProvider;
use App\Services\Signatures\SignatureProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

class EsignWebhookTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    public function test_webhook_is_404_while_no_provider_is_configured(): void
    {
        $this->assertFalse(app(SignatureProviderManager::class)->isAvailable());

        $this->postJson('/webhooks/esign/disabled', [])->assertNotFound();
        $this->postJson('/webhooks/esign/fake', [])->assertNotFound();
    }

    public function test_fake_provider_webhook_requires_valid_signature_and_is_idempotent(): void
    {
        config(['portal.esign.provider' => 'fake', 'portal.esign.webhook_secret' => 'test-secret']);
        $this->assertInstanceOf(FakeSignatureProvider::class, app(SignatureProviderManager::class)->provider());

        [$applicant] = $this->applicantWithInvitation();
        $doc = $this->assign($applicant, DocumentTemplate::factory()->create());
        $tx = SignatureTransaction::create([
            'applicant_document_id' => $doc->id, 'provider' => 'fake', 'provider_reference' => 'env-1', 'status' => 'sent',
        ]);

        $body = json_encode(['reference' => 'env-1', 'event_id' => 'evt-1', 'status' => 'completed', 'document_sha256' => str_repeat('a', 64)]);
        $ts = (string) time();

        $send = fn (string $sig, string $time) => $this->call('POST', '/webhooks/esign/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_TIMESTAMP' => $time,
            'HTTP_X_FAKE_SIGNATURE' => $sig,
        ], $body);

        $send('bad', $ts)->assertStatus(401);
        $send(hash_hmac('sha256', $ts.'.'.$body, 'test-secret'), (string) (time() - 3600))->assertStatus(401); // stale
        $this->assertSame('sent', $tx->fresh()->status);

        $good = hash_hmac('sha256', $ts.'.'.$body, 'test-secret');
        $send($good, $ts)->assertOk();
        $this->assertSame('completed', $tx->fresh()->status);

        $send($good, $ts)->assertOk(); // replay ignored
        $this->assertSame(1, \DB::table('signature_webhook_events')->count());
    }
}
