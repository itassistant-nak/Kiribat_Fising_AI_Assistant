<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Persists independent AI identification provider evaluations
     * (Muse Glimmer, OpenAI Vision) for audits, model drift monitoring,
     * and provider comparison without modifying human ground truth.
     */
    public function up(): void
    {
        Schema::create('fish_identification_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catch_id')->nullable()->constrained('catch_records')->nullOnDelete();
            $table->string('provider_name'); // 'muse_glimmer', 'openai'
            $table->string('model_identifier');
            $table->string('suggested_species')->nullable();
            $table->string('scientific_name')->nullable();
            $table->float('confidence')->nullable();
            $table->json('visible_traits')->nullable();
            $table->text('explanation')->nullable();
            $table->json('alternative_species')->nullable();
            $table->longText('raw_response')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('error_status')->nullable(); // 'success', 'failed', 'timeout', 'unconfigured', 'malformed_json'
            $table->timestamps();

            // Index for catch audit lookup and provider metrics
            $table->index(['catch_id', 'provider_name']);
            $table->index(['provider_name', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fish_identification_providers');
    }
};
