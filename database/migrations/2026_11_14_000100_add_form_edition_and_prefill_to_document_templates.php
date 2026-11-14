<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // Official form edition (e.g. "01/20/25") and the date it stops being valid
            // (e.g. Form I-9 "Expires 05/31/2027"), so HR is warned to replace it.
            $table->string('form_edition', 60)->nullable()->after('document_type_label');
            $table->date('form_expires_on')->nullable()->after('form_edition');
            // Where to type the applicant's answers onto the printable PDF (validated JSON).
            $table->json('prefill_map')->nullable()->after('print_asset_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['form_edition', 'form_expires_on', 'prefill_map']);
        });
    }
};
