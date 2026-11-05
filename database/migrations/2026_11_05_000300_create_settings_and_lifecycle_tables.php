<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('export_archives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);                 // pending | ready | failed | expired
            $table->string('disk', 40)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('download_name', 200)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reminder_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitation_id')->constrained('applicant_invitations')->cascadeOnDelete();
            $table->string('reminder_key', 60);
            $table->timestamp('sent_at');

            $table->unique(['invitation_id', 'reminder_key']);
        });

        // No foreign keys: these rows outlive purged applicants and reference
        // them only by the pseudonymous applicant_ref.
        Schema::create('retention_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('applicant_ref')->nullable()->index();
            $table->string('action', 30);                 // dry_run | purged | skipped_legal_hold | failed
            $table->string('trigger', 30)->nullable();     // scheduled | manual
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('result', 40)->nullable();
            $table->json('metadata')->nullable();          // counts only
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event', 80);
            $table->string('recipient_type', 20);          // hr | applicant
            $table->string('recipient_ref', 64)->nullable();
            $table->string('status', 20);
            $table->string('error_class', 120)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('retention_events');
        Schema::dropIfExists('reminder_dispatches');
        Schema::dropIfExists('export_archives');
        Schema::dropIfExists('settings');
    }
};
