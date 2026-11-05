<?php

namespace App\Services\Signatures;

use App\Models\ApplicantDocument;
use Illuminate\Http\Request;
use LogicException;

/**
 * Default: no provider has been selected or approved.
 */
class DisabledSignatureProvider implements SignatureProvider
{
    public function name(): string
    {
        return 'disabled';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function createEnvelope(ApplicantDocument $document, string $returnUrl): array
    {
        throw new LogicException('No e-signature provider is configured.');
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): array
    {
        throw new LogicException('No e-signature provider is configured.');
    }

    public function fetchCompletedArtifacts(string $reference): array
    {
        throw new LogicException('No e-signature provider is configured.');
    }
}
