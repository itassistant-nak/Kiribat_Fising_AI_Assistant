<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Advisory Zones Table
        Schema::create('advisory_zones', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('concern'); // e.g. Ciguatoxin / Dinoflagellate bloom
            $table->text('evidence')->nullable(); // Clinical cases, water/algae testing, field observations
            $table->string('classification')->default('suspected'); // suspected, under_investigation, authority-confirmed
            $table->string('status')->default('draft'); // draft, under_investigation, approved, published, archived
            $table->json('polygon_geojson'); // GeoJSON Polygon geometry
            $table->string('source')->default('Ministry of Fisheries & Marine Resources Development (MFMRD)');
            $table->date('review_date')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // 2. Many-to-Many Pivot Table: Advisory Zones <-> Species
        Schema::create('advisory_zone_species', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisory_zone_id')->constrained('advisory_zones')->onDelete('cascade');
            $table->foreignId('species_id')->constrained('species')->onDelete('cascade');
            $table->string('risk_level')->default('high'); // low, medium, high, extreme
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['advisory_zone_id', 'species_id']);
        });

        // 3. Advisory Audit Logs Table
        Schema::create('advisory_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisory_zone_id')->constrained('advisory_zones')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('action'); // created, updated, approved, published, unpublished, archived, species_synced
            $table->json('changes')->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_audit_logs');
        Schema::dropIfExists('advisory_zone_species');
        Schema::dropIfExists('advisory_zones');
    }
};
