<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->longText('photo_url')->nullable()->change();
        });

        Schema::table('catch_records', function (Blueprint $table) {
            $table->longText('photo_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->text('photo_url')->nullable()->change();
        });

        Schema::table('catch_records', function (Blueprint $table) {
            $table->text('photo_url')->nullable()->change();
        });
    }
};
