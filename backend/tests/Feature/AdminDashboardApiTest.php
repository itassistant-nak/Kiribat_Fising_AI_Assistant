<?php

namespace Tests\Feature;

use App\Models\CatchRecord;
use App\Models\FishingTrip;
use App\Models\Species;
use App\Models\TripRoutePoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $fisher;
    protected Species $speciesAtun;
    protected Species $speciesKuau;
    protected FishingTrip $trip;
    protected CatchRecord $catch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Ministry Admin',
            'email' => 'admin@fisheries.gov.ki',
            'password' => bcrypt('KiribatiAdmin2026!'),
            'role' => 'admin',
        ]);

        $this->fisher = User::create([
            'name' => 'Teititeki Tabua',
            'email' => 'teititeki@fisher.ki',
            'password' => bcrypt('FisherPass2026!'),
            'role' => 'fisher',
        ]);

        $this->speciesAtun = Species::create([
            'kiribati_name' => 'Te Atun',
            'english_name' => 'Skipjack Tuna',
            'scientific_name' => 'Katsuwonus pelamis',
            'ciguatera_risk' => 'low',
        ]);

        $this->speciesKuau = Species::create([
            'kiribati_name' => 'Te Kuau',
            'english_name' => 'Marbled Grouper',
            'scientific_name' => 'Epinephelus polyphekadion',
            'ciguatera_risk' => 'medium',
        ]);

        $this->trip = FishingTrip::create([
            'user_id' => $this->fisher->id,
            'vessel_name' => 'Te Raoi II',
            'started_at' => now()->subHours(6),
            'ended_at' => now()->subHour(),
            'status' => 'completed',
        ]);

        TripRoutePoint::create([
            'trip_id' => $this->trip->id,
            'latitude' => 1.3582,
            'longitude' => 172.9315,
            'sequence' => 1,
            'recorded_at' => now()->subHours(5),
        ]);

        TripRoutePoint::create([
            'trip_id' => $this->trip->id,
            'latitude' => 1.3820,
            'longitude' => 172.9680,
            'sequence' => 2,
            'recorded_at' => now()->subHours(4),
        ]);

        $this->catch = CatchRecord::create([
            'trip_id' => $this->trip->id,
            'user_id' => $this->fisher->id,
            'species_id' => $this->speciesAtun->id,
            'ai_species_suggestion' => 'Te Atun (Skipjack Tuna)',
            'ai_confidence' => 0.94,
            'quantity' => 5,
            'weight_kg' => 18.5,
            'caught_at' => now()->subHours(3),
            'photo_url' => 'https://example.com/fish.jpg',
            'latitude' => 1.3820,
            'longitude' => 172.9680,
            'verification_status' => 'pending',
        ]);
    }

    /**
     * Acceptance Test 1: Administrator login succeeds with valid credentials.
     */
    public function test_administrator_login_succeeds_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@fisheries.gov.ki',
            'password' => 'KiribatiAdmin2026!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'role', 'is_admin']
            ])
            ->assertJson([
                'user' => [
                    'email' => 'admin@fisheries.gov.ki',
                    'role' => 'admin',
                    'is_admin' => true,
                ]
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@fisheries.gov.ki',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Acceptance Test 2: Non-administrators cannot access restricted endpoints.
     */
    public function test_non_administrators_cannot_access_restricted_endpoints(): void
    {
        // 1. Unauthenticated request to admin stats
        $unauth = $this->getJson('/api/admin/stats');
        $unauth->assertStatus(401);

        // 2. Fisher request to admin stats
        $fisherToken = $this->fisher->createToken('test')->plainTextToken;
        $forbidden = $this->withHeader('Authorization', "Bearer {$fisherToken}")
            ->getJson('/api/admin/stats');
        $forbidden->assertStatus(403);

        // 3. Fisher attempt to verify catch
        $forbiddenVerify = $this->withHeader('Authorization', "Bearer {$fisherToken}")
            ->postJson("/api/admin/catches/{$this->catch->id}/verify", [
                'action' => 'approve'
            ]);
        $forbiddenVerify->assertStatus(403);
    }

    /**
     * Acceptance Test 3: Synchronized catches display correctly.
     */
    public function test_synchronized_catches_display_correctly(): void
    {
        $adminToken = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/catches');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'quantity',
                        'weight_kg',
                        'caught_at',
                        'photo_url',
                        'ai_species_suggestion',
                        'ai_confidence',
                        'verification_status',
                        'user' => ['name', 'email'],
                        'species' => ['kiribati_name', 'english_name'],
                    ]
                ],
                'count',
                'can_view_precise_gps',
            ])
            ->assertJsonPath('data.0.quantity', 5)
            ->assertJsonPath('data.0.weight_kg', 18.5)
            ->assertJsonPath('data.0.user.name', 'Teititeki Tabua')
            ->assertJsonPath('data.0.species.kiribati_name', 'Te Atun');
    }

    /**
     * Acceptance Test 4: Catch locations and routes display at stored coordinates for admins,
     * while protecting GPS privacy for unauthorized users.
     */
    public function test_catch_locations_and_routes_display_at_stored_coordinates(): void
    {
        // Admin sees accurate stored coordinates
        \Laravel\Sanctum\Sanctum::actingAs($this->admin);
        $adminCatch = $this->getJson('/api/catches');
        $adminCatch->assertStatus(200)
            ->assertJsonPath('data.0.latitude', 1.382)
            ->assertJsonPath('data.0.longitude', 172.968)
            ->assertJsonPath('data.0.gps_protected', false);

        // Admin sees trip route points
        $adminRoute = $this->getJson("/api/trips/{$this->trip->id}/route");
        $adminRoute->assertStatus(200)
            ->assertJsonPath('route_points.0.latitude', 1.3582)
            ->assertJsonPath('route_points.0.longitude', 172.9315);

        // Non-admin (fisher) sees redacted coordinates on catches to protect fisher privacy
        \Laravel\Sanctum\Sanctum::actingAs($this->fisher);
        $fisherCatches = $this->getJson('/api/catches');
        $fisherCatches->assertStatus(200)
            ->assertJsonPath('data.0.latitude', null)
            ->assertJsonPath('data.0.longitude', null)
            ->assertJsonPath('data.0.gps_protected', true);
    }

    /**
     * Acceptance Test 5: Species approval and correction persist in Laravel
     * while PRESERVING the original AI suggestion.
     */
    public function test_species_approval_and_correction_persist_preserving_original_ai_suggestion(): void
    {
        $adminToken = $this->admin->createToken('test')->plainTextToken;

        // 1. Approve AI suggestion
        $approveRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/catches/{$this->catch->id}/verify", [
                'action' => 'approve',
                'verification_notes' => 'Confirmed Skipjack',
            ]);

        $approveRes->assertStatus(200)
            ->assertJsonPath('catch.verification_status', 'verified')
            ->assertJsonPath('preserved_ai_suggestion.original_suggestion', 'Te Atun (Skipjack Tuna)');

        $this->assertDatabaseHas('catch_records', [
            'id' => $this->catch->id,
            'verification_status' => 'verified',
            'ai_species_suggestion' => 'Te Atun (Skipjack Tuna)', // Untouched!
        ]);

        // 2. Correct species to Te Kuau (Marbled Grouper)
        $correctRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/catches/{$this->catch->id}/verify", [
                'action' => 'correct',
                'species_id' => $this->speciesKuau->id,
                'verification_notes' => 'Distinct spotted pattern indicates Marbled Grouper.',
            ]);

        $correctRes->assertStatus(200)
            ->assertJsonPath('catch.verification_status', 'corrected')
            ->assertJsonPath('catch.species_id', $this->speciesKuau->id);

        // Crucial requirement: Verify DB still holds the ORIGINAL AI suggestion!
        $this->assertDatabaseHas('catch_records', [
            'id' => $this->catch->id,
            'species_id' => $this->speciesKuau->id,
            'verification_status' => 'corrected',
            'ai_species_suggestion' => 'Te Atun (Skipjack Tuna)', // Original AI suggestion preserved!
            'ai_confidence' => 0.94,
        ]);
    }

    /**
     * Acceptance Test 6: Filtering returns the correct records.
     */
    public function test_filtering_returns_the_correct_records(): void
    {
        $adminToken = $this->admin->createToken('test')->plainTextToken;

        // Create another catch for another species and user
        $fisher2 = User::create([
            'name' => 'Karotu Bwebwe',
            'email' => 'karotu@fisher.ki',
            'password' => bcrypt('secret'),
            'role' => 'fisher',
        ]);

        $catch2 = CatchRecord::create([
            'trip_id' => null,
            'user_id' => $fisher2->id,
            'species_id' => $this->speciesKuau->id,
            'ai_species_suggestion' => 'Te Kuau',
            'ai_confidence' => 0.85,
            'quantity' => 1,
            'weight_kg' => 4.0,
            'caught_at' => now()->subDay(),
            'verification_status' => 'verified',
        ]);

        // Filter by fisherman name
        $resFisher = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/catches?fisherman=Teititeki');
        $resFisher->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->catch->id);

        // Filter by species ID
        $resSpecies = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson("/api/catches?species_id={$this->speciesKuau->id}");
        $resSpecies->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $catch2->id);

        // Filter by status 'pending'
        $resStatus = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/catches?status=pending');
        $resStatus->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->catch->id);
    }

    /**
     * Verify Dashboard statistics based on actual records.
     */
    public function test_dashboard_statistics_based_on_actual_records(): void
    {
        $adminToken = $this->admin->createToken('test')->plainTextToken;

        $stats = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/admin/stats');

        $stats->assertStatus(200)
            ->assertJson([
                'overview' => [
                    'total_catches' => 1,
                    'total_weight_kg' => 18.5,
                    'total_quantity' => 5,
                    'pending_verification' => 1,
                    'verified_catches' => 0,
                ]
            ]);
    }

    /**
     * Test admin changing catch image automatically marks record as 'not_a_catch' (only catches from fishermen are genuine catches).
     */
    public function test_admin_can_correct_catch_image_and_verify_with_ai_suggestion(): void
    {
        $adminToken = $this->admin->createToken('test')->plainTextToken;

        $newPhotoUrl = 'https://example.com/corrected_kuau_specimen.jpg';

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/catches/{$this->catch->id}/verify", [
                'action' => 'correct',
                'species_id' => $this->speciesKuau->id,
                'photo_url' => $newPhotoUrl,
                'ai_species_suggestion' => 'Te Kuau (Camouflage Grouper)',
                'ai_confidence' => 0.96,
                'verification_notes' => 'Replaced blurry photo with verified specimen image. Re-analyzed by AI and confirmed by officer.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('photo_updated', true)
            ->assertJsonPath('catch.photo_url', $newPhotoUrl)
            ->assertJsonPath('catch.species_id', $this->speciesKuau->id)
            ->assertJsonPath('catch.is_catch', false)
            ->assertJsonPath('catch.verification_status', 'not_a_catch');

        $this->assertDatabaseHas('catch_records', [
            'id' => $this->catch->id,
            'photo_url' => $newPhotoUrl,
            'species_id' => $this->speciesKuau->id,
            'is_catch' => false,
            'verification_status' => 'not_a_catch',
            'ai_species_suggestion' => 'Te Atun (Skipjack Tuna)', // Original submission preserved
        ]);

        $updatedCatch = CatchRecord::find($this->catch->id);
        $this->assertIsArray($updatedCatch->ai_metadata);
        $this->assertEquals('https://example.com/fish.jpg', $updatedCatch->ai_metadata['original_photo_url']);
        $this->assertEquals('Te Kuau (Camouflage Grouper)', $updatedCatch->ai_metadata['corrected_ai_suggestion']);
        $this->assertTrue($updatedCatch->ai_metadata['not_a_catch']);
    }

    /**
     * Test admin can upload new photo file during catch verification, marking as not_a_catch.
     */
    public function test_admin_can_upload_new_catch_image_file_and_approve(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $adminToken = $this->admin->createToken('test')->plainTextToken;
        $file = \Illuminate\Http\UploadedFile::fake()->create('clear_specimen.jpg', 100, 'image/jpeg');

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->post("/api/admin/catches/{$this->catch->id}/verify", [
                'action' => 'approve',
                'photo' => $file,
                'species_id' => $this->speciesAtun->id,
                'verification_notes' => 'Uploaded clear photo to replace original.',
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('photo_updated', true)
            ->assertJsonPath('catch.is_catch', false)
            ->assertJsonPath('catch.verification_status', 'not_a_catch');

        $updatedCatch = CatchRecord::find($this->catch->id);
        $this->assertStringContainsString('catch_corrected_', $updatedCatch->photo_url);
        $this->assertFalse((bool)$updatedCatch->is_catch);
    }

    /**
     * Test admin can directly mark an invalid catch as 'not_a_catch' and verify exclusion from stats.
     */
    public function test_admin_can_mark_submission_as_not_a_catch_and_exclude_from_stats(): void
    {
        $adminToken = $this->admin->createToken('test')->plainTextToken;

        // 1. Mark catch as not a catch
        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/catches/{$this->catch->id}/verify", [
                'action' => 'not_a_catch',
                'verification_notes' => 'Photograph is debris/seaweed, not an artisanal fish catch.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('catch.is_catch', false)
            ->assertJsonPath('catch.verification_status', 'not_a_catch');

        // 2. Fetch stats and verify this record is excluded from official catch counts
        $statsRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/admin/stats');

        $statsRes->assertStatus(200)
            ->assertJsonPath('overview.total_catches', 0)
            ->assertJsonPath('overview.total_weight_kg', 0)
            ->assertJsonPath('overview.total_quantity', 0)
            ->assertJsonPath('overview.not_a_catch_count', 1);

        // 3. Filter by not_a_catch returns the record
        $filterRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/catches?status=not_a_catch');

        $filterRes->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $this->catch->id)
            ->assertJsonPath('data.0.is_catch', false);
    }
}

