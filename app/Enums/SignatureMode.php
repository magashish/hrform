<?php

namespace App\Enums;

/**
 * See docs/signature-compliance-decisions.md. provider_esign is unavailable
 * until a provider is configured and an approval is recorded.
 */
enum SignatureMode: string
{
    case None = 'none';
    case WetSignatureUpload = 'wet_signature_upload';
    case ProviderEsign = 'provider_esign';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No signature',
            self::WetSignatureUpload => 'Print, sign by hand and upload',
            self::ProviderEsign => 'Electronic signature provider',
        };
    }
}
