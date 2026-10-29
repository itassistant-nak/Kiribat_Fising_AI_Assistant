<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catch_records', function (Blueprint $table) {
            $table->boolean('is_catch')->default(true)->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('catch_records', function (Blueprint $table) {
            $table->dropColumn('is_catch');
        });
    }
};
