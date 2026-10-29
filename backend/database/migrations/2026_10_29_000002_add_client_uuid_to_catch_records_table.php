<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Day 5 (P0-10): offline clients send their local catch UUID as
     * client_uuid so a retried upload replays the original row instead
     * of duplicating it (mirrors trip (trip_id, sequence) idempotency).
     * Nullable + unique: web/admin creates without a UUID keep working
     * (multiple NULLs allowed on SQLite and MySQL).
     */
    public function up(): void
    {
        Schema::table('catch_records', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable()->unique()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('catch_records', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
