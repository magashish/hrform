<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Optional position, shown in the Contingent Job Offer notice ({position_title}). */
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('position_title', 150)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn('position_title');
        });
    }
};
