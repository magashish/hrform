<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - Notices: several notices (e.g. Contingent Job Offer, FCRA summary of
     *   rights, privacy disclosure), each versioned and acknowledged
     *   separately before any form. Existing versions become "disclosure".
     * - Stages: each assigned document belongs to a stage (before screening,
     *   after screening clears, day 1) and is hidden from the applicant until
     *   HR releases it.
     */
    public function up(): void
    {
        Schema::table('consent_versions', function (Blueprint $table) {
            $table->string('notice_key', 40)->default('disclosure')->after('id');
            $table->string('attachment_path')->nullable()->after('body_sha256');
            $table->string('attachment_name', 200)->nullable()->after('attachment_path');
            $table->char('attachment_sha256', 64)->nullable()->after('attachment_name');
        });

        Schema::table('consent_versions', function (Blueprint $table) {
            $table->dropUnique(['version']);
            $table->unique(['notice_key', 'version']);
            $table->index(['notice_key', 'active']);
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('default_stage', 30)->default('initial')->after('auto_assign');
        });

        Schema::table('applicant_documents', function (Blueprint $table) {
            $table->string('stage', 30)->default('initial')->after('required');
            $table->timestamp('released_at')->nullable()->after('assigned_at');
        });

        // Everything assigned so far was visible to applicants immediately.
        DB::table('applicant_documents')->whereNull('released_at')->update(['released_at' => DB::raw('assigned_at')]);
    }

    public function down(): void
    {
        Schema::table('applicant_documents', function (Blueprint $table) {
            $table->dropColumn(['stage', 'released_at']);
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('default_stage');
        });

        Schema::table('consent_versions', function (Blueprint $table) {
            $table->dropIndex(['notice_key', 'active']);
            $table->dropUnique(['notice_key', 'version']);
            $table->unique('version');
        });

        Schema::table('consent_versions', function (Blueprint $table) {
            $table->dropColumn(['notice_key', 'attachment_path', 'attachment_name', 'attachment_sha256']);
        });
    }
};
