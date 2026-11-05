<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Future e-signature provider evidence. Unused while the provider is
     * "disabled"; see docs/signature-compliance-decisions.md.
     */
    public function up(): void
    {
        Schema::create('signature_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('applicant_document_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_reference', 200);
            $table->string('status', 40);
            $table->json('signer_evidence_refs')->nullable();
            $table->json('attestation')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->string('certificate_path')->nullable();
            $table->char('last_webhook_hash', 64)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
        });

        Schema::create('signature_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 200);
            $table->char('payload_sha256', 64);
            $table->timestamp('received_at');

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_webhook_events');
        Schema::dropIfExists('signature_transactions');
    }
};
