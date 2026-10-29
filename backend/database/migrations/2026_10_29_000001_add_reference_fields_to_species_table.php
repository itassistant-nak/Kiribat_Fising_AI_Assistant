<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Day 4 (P1-06): structured reference-database flags for the species
     * catalog. Existing rows default to needs_review/species so nothing
     * is silently treated as verified; the seeders explicitly mark the
     * 9 curated entries verified and the 35 imported entries
     * needs_review with their verbatim scientific name preserved.
     * Portable across SQLite (tests/demo) and MySQL 8 (production).
     */
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->string('verification_status', 30)->default('needs_review')->after('ciguatera_risk');
            $table->string('record_type', 30)->default('species')->after('verification_status');
            $table->string('scientific_name_as_provided')->nullable()->after('scientific_name');
        });
    }

    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'record_type', 'scientific_name_as_provided']);
        });
    }
};
