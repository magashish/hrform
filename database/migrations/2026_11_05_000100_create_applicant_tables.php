<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();      // used in HR URLs
            $table->uuid('applicant_ref')->unique();  // pseudonymous ref for audit/retention
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254)->index();
            $table->string('status', 40)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('deletion_due_at')->nullable()->index();
            $table->boolean('legal_hold')->default(false);
            $table->text('legal_hold_reason')->nullable();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });

        Schema::create('applicant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique(); // HMAC-SHA256 of the code; raw code never stored
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 40)->nullable();
            $table->string('delivery_status', 20)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('applicant_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitation_id')->constrained('applicant_invitations')->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('consent_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 40)->unique();
            $table->string('title', 200);
            $table->longText('body');
            $table->char('body_sha256', 64);
            $table->boolean('active')->default(false)->index();
            $table->timestamp('effective_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('applicant_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consent_version_id')->constrained()->restrictOnDelete();
            $table->timestamp('acknowledged_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->unique(['applicant_id', 'consent_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_consents');
        Schema::dropIfExists('consent_versions');
        Schema::dropIfExists('applicant_otps');
        Schema::dropIfExists('applicant_invitations');
        Schema::dropIfExists('applicants');
    }
};
