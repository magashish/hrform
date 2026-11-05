<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();            // e.g. I9, SSA89
            $table->string('name', 150);
            $table->string('document_type_label', 60);        // used in generated filenames
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('input_mode', 30);                 // online_form | upload | acknowledgement
            $table->string('signature_mode', 30);             // none | wet_signature_upload | provider_esign
            $table->boolean('required_by_default')->default(true);
            $table->boolean('auto_assign')->default(false);
            $table->json('allowed_extensions');
            $table->unsignedInteger('max_size_kb');
            $table->json('supporting_requirements')->nullable();
            $table->string('filename_pattern', 200)->nullable();
            $table->string('print_asset_path')->nullable();   // blank form on the private disk
            $table->string('print_asset_name', 200)->nullable();
            $table->char('print_asset_sha256', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 60);
            $table->string('label', 200);
            $table->text('help_text')->nullable();
            $table->string('type', 30);
            $table->json('config')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('sensitive')->default(false);
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();

            $table->unique(['document_template_id', 'field_key']);
        });

        Schema::create('signature_mode_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->cascadeOnDelete();
            $table->string('signature_mode', 30);
            $table->string('authorized_by_name', 150);
            $table->string('approval_reference', 200);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('authorized_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('applicant_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_template_id')->constrained()->restrictOnDelete();
            $table->boolean('required')->default(true);
            $table->string('status', 40)->index();
            $table->text('form_data')->nullable();            // encrypted JSON
            $table->string('input_mode_snapshot', 30);
            $table->string('signature_mode_snapshot', 30);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['applicant_id', 'document_template_id']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('applicant_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('disk', 40);
            $table->string('storage_path');
            $table->string('original_filename', 255);
            $table->string('generated_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('scan_status', 20);
            $table->string('uploaded_by_type', 20);
            $table->string('uploaded_by_id', 64)->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamp('superseded_at')->nullable();

            $table->unique(['applicant_document_id', 'version_number']);
        });

        Schema::create('supporting_files', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('applicant_document_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_key', 60);
            $table->unsignedInteger('version_number');
            $table->string('disk', 40);
            $table->string('storage_path');
            $table->string('original_filename', 255);
            $table->string('generated_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('scan_status', 20);
            $table->timestamp('uploaded_at');
            $table->timestamp('superseded_at')->nullable();

            $table->index(['applicant_document_id', 'requirement_key']);
        });

        Schema::create('correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('applicant_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('scope', 80)->default('document');
            $table->text('message');
            $table->string('status', 20)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_requests');
        Schema::dropIfExists('supporting_files');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('applicant_documents');
        Schema::dropIfExists('signature_mode_approvals');
        Schema::dropIfExists('document_fields');
        Schema::dropIfExists('document_templates');
    }
};
