<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $applicant_document_id
 * @property string $provider
 * @property string $provider_reference
 * @property string $status
 * @property string|null $document_sha256
 * @property string|null $last_webhook_hash
 * @property Carbon|null $signed_at
 */
class SignatureTransaction extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signer_evidence_refs' => 'array',
            'attestation' => 'array',
            'signed_at' => 'datetime',
        ];
    }
}
