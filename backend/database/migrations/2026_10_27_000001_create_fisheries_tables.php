<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Species catalog with Kiribati vernacular names
        Schema::create('species', function (Blueprint $table) {
            $table->id();
            $table->string('kiribati_name');
            $table->string('english_name');
            $table->string('scientific_name');
            $table->string('ciguatera_risk')->default('low'); // low, medium, high, unknown
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Fishing trips recorded by fishers
        Schema::create('fishing_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('vessel_name')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('status')->default('completed'); // in_progress, completed
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // GPS route tracking points for trips
        Schema::create('trip_route_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('fishing_trips')->onDelete('cascade');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->integer('sequence')->default(0);
            $table->dateTime('recorded_at');
            $table->timestamps();
        });

        // Catch records with Meta AI suggestion & human verification fields
        Schema::create('catch_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->nullable()->constrained('fishing_trips')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            // Original AI Suggestion preserved immutably
            $table->string('ai_species_suggestion')->nullable();
            $table->float('ai_confidence')->nullable();
            $table->json('ai_metadata')->nullable();
            // Catch metrics
            $table->integer('quantity')->default(1);
            $table->decimal('weight_kg', 8, 2)->default(0.00);
            $table->dateTime('caught_at');
            $table->text('photo_url')->nullable();
            // GPS Location
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // Human Verification workflow
            $table->string('verification_status')->default('pending'); // pending, verified, corrected, flagged
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catch_records');
        Schema::dropIfExists('trip_route_points');
        Schema::dropIfExists('fishing_trips');
        Schema::dropIfExists('species');
    }
};
