<?php

namespace App\Services\Signatures;

/**
 * Resolves the configured provider. Only "disabled" is available in
 * production until a real adapter is written and approved.
 */
class SignatureProviderManager
{
    public function provider(): SignatureProvider
    {
        return match ((string) config('portal.esign.provider', 'disabled')) {
            'fake' => new FakeSignatureProvider,
            default => new DisabledSignatureProvider,
        };
    }

    public function isAvailable(): bool
    {
        return $this->provider()->isAvailable();
    }
}
