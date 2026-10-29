<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Species;
use App\Models\CatchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AiFishIdentificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // KB-only identification: these tests must never depend on (or bill)
        // a developer's real MODEL_API_KEY from .env.
        config(['services.meta_ai.api_key' => null]);
    }

    public function test_ai_identification_endpoint_returns_suggestions_for_image_url()
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=600',
            'hint' => 'paddletail',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'image_url',
            'identification' => [
                'kiribati_name',
                'english_name',
                'scientific_name',
                'confidence',
                'visual_features',
                'candidates',
            ],
            'disclaimer',
        ]);

        $data = $response->json('identification');
        $this->assertEquals('Te Bwere', $data['kiribati_name']);
        $this->assertEquals('Paddletail Snapper', $data['english_name']);
        $this->assertGreaterThanOrEqual(0.85, $data['confidence']);
    }

    public function test_ai_identification_validates_missing_image()
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/ai/identify-fish', []);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'MISSING_IMAGE']);
    }

    public function test_admin_saves_new_catch_specimen_with_photo_name_and_kiribati_name()
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/catches', [
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=600',
            'english_name' => 'Coral Trout',
            'kiribati_name' => 'Te Morikoi',
            'scientific_name' => 'Plectropomus maculatus',
            'ai_species_suggestion' => 'Te Morikoi (Coral Trout)',
            'ai_confidence' => 0.94,
            'weight_kg' => 3.80,
            'quantity' => 1,
            'verification_notes' => 'Confirmed by Senior Marine Biologist',
            'verification_status' => 'verified',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('catch.verification_status', 'verified');
        $response->assertJsonPath('catch.verified_by_user_id', $admin->id);

        // Verify persisted in database
        $this->assertDatabaseHas('catch_records', [
            'ai_species_suggestion' => 'Te Morikoi (Coral Trout)',
            'verification_status' => 'verified',
            'verified_by_user_id' => $admin->id,
        ]);

        // Verify species was created/linked
        $this->assertDatabaseHas('species', [
            'kiribati_name' => 'Te Morikoi',
            'english_name' => 'Coral Trout',
        ]);
    }

    public function test_admin_can_add_species_to_reference_catalog()
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/species', [
            'kiribati_name' => 'Te Baibo',
            'english_name' => 'Peacock Grouper',
            'scientific_name' => 'Cephalopholis argus',
            'ciguatera_risk' => 'high',
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=600',
            'notes' => 'Bioaccumulator on Tarawa lagoon reefs',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('species', [
            'kiribati_name' => 'Te Baibo',
            'english_name' => 'Peacock Grouper',
        ]);
    }

    public function test_ai_identification_with_catalog_species_hint_returns_matching_species()
    {
        $admin = User::where('role', 'admin')->first();

        // Create a custom catalog species in DB (name must stay unused by
        // the seeded catalog: Te Koinawa is now a real SQL #33 entry)
        $species = Species::create([
            'kiribati_name' => 'Te Morikoi',
            'english_name' => 'Spotted Eagle Ray',
            'scientific_name' => 'Aetobatus ocellatus',
            'ciguatera_risk' => 'low',
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/ai/identify-fish', [
            'image_url' => $species->photo_url,
            'hint' => 'Te Morikoi',
        ]);

        $response->assertStatus(200);
        $data = $response->json('identification');
        $this->assertEquals('Te Morikoi', $data['kiribati_name']);
        $this->assertEquals('Spotted Eagle Ray', $data['english_name']);
        $this->assertEquals('Aetobatus ocellatus', $data['scientific_name']);
    }
}

