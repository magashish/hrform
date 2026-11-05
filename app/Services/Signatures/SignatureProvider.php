<?php

namespace App\Services\Signatures;

use App\Models\ApplicantDocument;
use Illuminate\Http\Request;

/**
 * Boundary for a future third-party e-signature provider (DocuSign, Adobe
 * Acrobat Sign, ...). No provider is selected; see
 * docs/signature-compliance-decisions.md. Implementations must:
 *  - create an envelope/agreement for one applicant document,
 *  - verify webhook authenticity (signature + timestamp) before parsing,
 *  - return evidence references (envelope id, audit certificate, hash),
 *    never only a signature image.
 */
interface SignatureProvider
{
    public function name(): string;

    public function isAvailable(): bool;

    /**
     * Starts a signing ceremony and returns the provider reference and a
     * one-time redirect URL for the signer.
     *
     * @return array{reference: string, redirect_url: string}
     */
    public function createEnvelope(ApplicantDocument $document, string $returnUrl): array;

    /**
     * Verifies the webhook's authenticity. Must be called before parseWebhook().
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * @return array{reference: string, event_id: string, status: string, signed_at: ?string, document_sha256: ?string}
     */
    public function parseWebhook(Request $request): array;

    /**
     * Downloads the completed document and audit certificate as raw bytes.
     *
     * @return array{document: string, certificate: ?string}
     */
    public function fetchCompletedArtifacts(string $reference): array;
}
