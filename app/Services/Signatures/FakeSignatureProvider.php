<?php

namespace App\Services\Signatures;

use App\Models\ApplicantDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Test double used only by the automated test suite. Webhooks are signed
 * with HMAC-SHA256 over "timestamp.body" using ESIGN_WEBHOOK_SECRET.
 */
class FakeSignatureProvider implements SignatureProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function isAvailable(): bool
    {
        return app()->environment('testing') && (string) config('portal.esign.webhook_secret') !== '';
    }

    public function createEnvelope(ApplicantDocument $document, string $returnUrl): array
    {
        return ['reference' => 'fake-'.Str::uuid(), 'redirect_url' => $returnUrl];
    }

    public function verifyWebhook(Request $request): bool
    {
        $timestamp = (string) $request->header('X-Fake-Timestamp');
        $signature = (string) $request->header('X-Fake-Signature');

        if ($timestamp === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), (string) config('portal.esign.webhook_secret'));

        return hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): array
    {
        return [
            'reference' => (string) $request->input('reference'),
            'event_id' => (string) $request->input('event_id'),
            'status' => (string) $request->input('status'),
            'signed_at' => $request->input('signed_at'),
            'document_sha256' => $request->input('document_sha256'),
        ];
    }

    public function fetchCompletedArtifacts(string $reference): array
    {
        return ['document' => '%PDF-1.4 fake signed document', 'certificate' => 'fake certificate'];
    }
}
