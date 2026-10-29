<?php

namespace Tests\Feature;

use App\Models\AdvisoryZone;
use App\Models\AdvisoryAuditLog;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvisoryZoneApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $reviewer;
    protected User $fisher;
    protected Species $speciesOkaoka;
    protected Species $speciesBwere;
    protected Species $speciesKuau;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Ministry Admin (HQ)',
            'email' => 'admin@fisheries.gov.ki',
            'password' => bcrypt('KiribatiAdmin2026!'),
            'role' => 'admin',
        ]);

        $this->reviewer = User::create([
            'name' => 'Marine Biologist Reviewer',
            'email' => 'reviewer@fisheries.gov.ki',
            'password' => bcrypt('ReviewerPass2026!'),
            'role' => 'reviewer',
        ]);

        $this->fisher = User::create([
            'name' => 'Teititeki Tabua (Fisher)',
            'email' => 'teititeki@fisher.ki',
            'password' => bcrypt('FisherPass2026!'),
            'role' => 'fisher',
        ]);

        $this->speciesOkaoka = Species::create([
            'kiribati_name' => 'Te Okaoka',
            'english_name' => 'Titan Triggerfish',
            'scientific_name' => 'Balistoides viridescens',
            'ciguatera_risk' => 'high',
        ]);

        $this->speciesBwere = Species::create([
            'kiribati_name' => 'Te Bwere',
            'english_name' => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'ciguatera_risk' => 'medium',
        ]);

        $this->speciesKuau = Species::create([
            'kiribati_name' => 'Te Kuau',
            'english_name' => 'Marbled Grouper',
            'scientific_name' => 'Epinephelus polyphekadion',
            'ciguatera_risk' => 'medium',
        ]);
    }

    /**
     * Acceptance Test 1: An authorized editor creates a valid polygon.
     */
    public function test_authorized_editor_creates_valid_polygon(): void
    {
        $payload = [
            'title' => '[DEMO - SYNTHETIC] Western Reef Ciguatera Caution',
            'description' => 'Field testing polygon advisory creation.',
            'concern' => 'Ciguatera fish poisoning / dinoflagellate risk',
            'evidence' => 'Hospital clinical report and water sampling confirmed Gambierdiscus.',
            'classification' => 'under_investigation',
            'status' => 'draft',
            'source' => 'MFMRD & Ministry of Health',
            'review_date' => '2026-11-20',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9150, 1.3500],
                    [172.9350, 1.3500],
                    [172.9350, 1.3700],
                    [172.9150, 1.3700],
                    [172.9150, 1.3500],
                ]],
            ],
            'species_ids' => [$this->speciesOkaoka->id],
        ];

        // Authorized reviewer creates zone
        $response = $this->actingAs($this->reviewer)
            ->postJson('/api/advisory-zones', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', '[DEMO - SYNTHETIC] Western Reef Ciguatera Caution')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.classification', 'under_investigation')
            ->assertJsonPath('data.created_by_user_id', $this->reviewer->id);

        $this->assertDatabaseHas('advisory_zones', [
            'title' => '[DEMO - SYNTHETIC] Western Reef Ciguatera Caution',
            'status' => 'draft',
        ]);
    }

    /**
     * Acceptance Test 2: Multiple species can be assigned to one zone.
     */
    public function test_multiple_species_can_be_assigned_to_one_zone(): void
    {
        $payload = [
            'title' => '[DEMO - SYNTHETIC] Multi-Species Ciguatera Warning',
            'concern' => 'High CFP bioaccumulation in predatory species',
            'evidence' => 'Field survey confirmed multiple species toxicity.',
            'classification' => 'authority-confirmed',
            'status' => 'draft',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9200, 1.3500],
                    [172.9400, 1.3500],
                    [172.9400, 1.3700],
                    [172.9200, 1.3700],
                    [172.9200, 1.3500],
                ]],
            ],
            'species_ids' => [
                $this->speciesOkaoka->id,
                $this->speciesBwere->id,
                $this->speciesKuau->id,
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->postJson('/api/advisory-zones', $payload);

        $response->assertStatus(201)
            ->assertJsonCount(3, 'data.species');

        $zoneId = $response->json('data.id');
        $this->assertDatabaseHas('advisory_zone_species', [
            'advisory_zone_id' => $zoneId,
            'species_id' => $this->speciesOkaoka->id,
        ]);
        $this->assertDatabaseHas('advisory_zone_species', [
            'advisory_zone_id' => $zoneId,
            'species_id' => $this->speciesBwere->id,
        ]);
        $this->assertDatabaseHas('advisory_zone_species', [
            'advisory_zone_id' => $zoneId,
            'species_id' => $this->speciesKuau->id,
        ]);
    }

    /**
     * Acceptance Test 3: Invalid GeoJSON is rejected.
     */
    public function test_invalid_geojson_is_rejected(): void
    {
        // 1. Non-polygon type rejected
        $badTypePayload = [
            'title' => '[DEMO] Bad Type',
            'concern' => 'Test',
            'classification' => 'suspected',
            'polygon_geojson' => [
                'type' => 'Point',
                'coordinates' => [172.9200, 1.3500],
            ],
        ];

        $res1 = $this->actingAs($this->admin)
            ->postJson('/api/advisory-zones', $badTypePayload);
        $res1->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_GEOJSON');

        // 2. Unclosed ring (first != last) rejected
        $unclosedPayload = [
            'title' => '[DEMO] Unclosed Ring',
            'concern' => 'Test',
            'classification' => 'suspected',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9150, 1.3500],
                    [172.9350, 1.3500],
                    [172.9350, 1.3700],
                    [172.9150, 1.3700], // Missing closing vertex [172.9150, 1.3500]
                ]],
            ],
        ];

        $res2 = $this->actingAs($this->admin)
            ->postJson('/api/advisory-zones', $unclosedPayload);
        $res2->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_GEOJSON');

        // 3. Insufficient vertices (< 4 positions) rejected
        $tooFewPayload = [
            'title' => '[DEMO] Too Few Vertices',
            'concern' => 'Test',
            'classification' => 'suspected',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9150, 1.3500],
                    [172.9350, 1.3500],
                    [172.9150, 1.3500],
                ]],
            ],
        ];

        $res3 = $this->actingAs($this->admin)
            ->postJson('/api/advisory-zones', $tooFewPayload);
        $res3->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_GEOJSON');

        // 4. Out of bounds coordinates rejected
        $outOfBoundsPayload = [
            'title' => '[DEMO] Out Of Bounds',
            'concern' => 'Test',
            'classification' => 'suspected',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [200.0, 1.3500], // longitude > 180
                    [172.9350, 1.3500],
                    [172.9350, 1.3700],
                    [200.0, 1.3500],
                ]],
            ],
        ];

        $res4 = $this->actingAs($this->admin)
            ->postJson('/api/advisory-zones', $outOfBoundsPayload);
        $res4->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_GEOJSON');
    }

    /**
     * Acceptance Test 4: An authorized approver publishes an advisory.
     */
    public function test_authorized_approver_publishes_advisory(): void
    {
        $zone = AdvisoryZone::create([
            'title' => '[DEMO] Ready for Publication',
            'concern' => 'CFP Warning',
            'classification' => 'authority-confirmed',
            'status' => 'draft',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9150, 1.3500],
                    [172.9350, 1.3500],
                    [172.9350, 1.3700],
                    [172.9150, 1.3700],
                    [172.9150, 1.3500],
                ]],
            ],
            'source' => 'MFMRD',
            'created_by_user_id' => $this->reviewer->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/advisory-zones/{$zone->id}/publish");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.approved_by_user_id', $this->admin->id);

        $this->assertDatabaseHas('advisory_zones', [
            'id' => $zone->id,
            'status' => 'published',
            'approved_by_user_id' => $this->admin->id,
        ]);

        // Audit log records publication
        $this->assertDatabaseHas('advisory_audit_logs', [
            'advisory_zone_id' => $zone->id,
            'user_id' => $this->admin->id,
            'action' => 'published',
        ]);
    }

    /**
     * Acceptance Test 5: Unauthorized publication is rejected.
     */
    public function test_unauthorized_publication_is_rejected(): void
    {
        $zone = AdvisoryZone::create([
            'title' => '[DEMO] Reviewer Cannot Publish',
            'concern' => 'CFP Warning',
            'classification' => 'under_investigation',
            'status' => 'draft',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9150, 1.3500],
                    [172.9350, 1.3500],
                    [172.9350, 1.3700],
                    [172.9150, 1.3700],
                    [172.9150, 1.3500],
                ]],
            ],
            'source' => 'MFMRD',
            'created_by_user_id' => $this->reviewer->id,
        ]);

        // 1. Reviewer attempt to publish directly is rejected with 403
        $reviewerPublish = $this->actingAs($this->reviewer)
            ->postJson("/api/advisory-zones/{$zone->id}/publish");

        $reviewerPublish->assertStatus(403)
            ->assertJsonPath('code', 'ADMIN_APPROVAL_REQUIRED');

        // 2. Reviewer attempt to update status to published is rejected with 403
        $reviewerUpdate = $this->actingAs($this->reviewer)
            ->putJson("/api/advisory-zones/{$zone->id}", [
                'status' => 'published',
            ]);

        $reviewerUpdate->assertStatus(403)
            ->assertJsonPath('code', 'ADMIN_APPROVAL_REQUIRED');

        // 3. Fisher attempt to publish is rejected with 403
        $fisherPublish = $this->actingAs($this->fisher)
            ->postJson("/api/advisory-zones/{$zone->id}/publish");

        $fisherPublish->assertStatus(403);

        // Zone status remains draft
        $this->assertDatabaseHas('advisory_zones', [
            'id' => $zone->id,
            'status' => 'draft',
        ]);
    }

    /**
     * Acceptance Test 6: Published zones appear on the administrator map and active queries;
     * draft zones are hidden from ordinary fishers.
     */
    public function test_published_zones_appear_and_drafts_hidden_from_fishers(): void
    {
        $publishedZone = AdvisoryZone::create([
            'title' => '[DEMO] Published Active Warning',
            'concern' => 'CFP Warning',
            'classification' => 'authority-confirmed',
            'status' => 'published',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9150, 1.3500],
                    [172.9350, 1.3500],
                    [172.9350, 1.3700],
                    [172.9150, 1.3700],
                    [172.9150, 1.3500],
                ]],
            ],
            'source' => 'MFMRD',
            'created_by_user_id' => $this->admin->id,
        ]);

        $draftZone = AdvisoryZone::create([
            'title' => '[DEMO] Confidential Draft Zone',
            'concern' => 'Unconfirmed report',
            'classification' => 'suspected',
            'status' => 'draft',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [173.0000, 1.3400],
                    [173.0200, 1.3400],
                    [173.0200, 1.3600],
                    [173.0000, 1.3600],
                    [173.0000, 1.3400],
                ]],
            ],
            'source' => 'MFMRD',
            'created_by_user_id' => $this->admin->id,
        ]);

        // Fisher query only returns published zones
        $fisherQuery = $this->actingAs($this->fisher)
            ->getJson('/api/advisory-zones');

        $fisherQuery->assertStatus(200);
        $fisherData = $fisherQuery->json('data');
        $this->assertCount(1, $fisherData);
        $this->assertEquals('[DEMO] Published Active Warning', $fisherData[0]['title']);

        // Admin query returns both draft and published zones
        $adminQuery = $this->actingAs($this->admin)
            ->getJson('/api/advisory-zones');

        $adminQuery->assertStatus(200);
        $adminData = $adminQuery->json('data');
        $this->assertCount(2, $adminData);
    }

    /**
     * Acceptance Test 7: Inside and outside coordinate tests return correct results.
     */
    public function test_inside_and_outside_coordinate_tests_return_correct_results(): void
    {
        // Published zone covering:
        // Longitude: 172.9150 to 172.9350
        // Latitude: 1.3500 to 1.3700
        $zone = AdvisoryZone::create([
            'title' => '[DEMO] Betio Passage Ciguatera Warning',
            'concern' => 'Gambierdiscus toxicus bloom',
            'classification' => 'authority-confirmed',
            'status' => 'published',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9150, 1.3500],
                    [172.9350, 1.3500],
                    [172.9350, 1.3700],
                    [172.9150, 1.3700],
                    [172.9150, 1.3500],
                ]],
            ],
            'source' => 'MFMRD',
            'created_by_user_id' => $this->admin->id,
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'published_at' => now(),
        ]);

        $zone->species()->attach($this->speciesOkaoka->id);

        // Point A: Inside zone (Lat: 1.3580, Lng: 172.9250)
        $insideCheck = $this->actingAs($this->fisher)
            ->postJson('/api/advisory-zones/check-point', [
                'latitude' => 1.3580,
                'longitude' => 172.9250,
            ]);

        $insideCheck->assertStatus(200)
            ->assertJsonPath('inside_advisory_zone', true)
            ->assertJsonPath('zones_count', 1)
            ->assertJsonPath('matching_zones.0.title', '[DEMO] Betio Passage Ciguatera Warning')
            ->assertJsonPath('affected_species.0.kiribati_name', 'Te Okaoka');

        // Point B: Outside zone (Lat: 1.3820, Lng: 172.9680 - Mid Lagoon)
        $outsideCheck = $this->actingAs($this->fisher)
            ->postJson('/api/advisory-zones/check-point', [
                'latitude' => 1.3820,
                'longitude' => 172.9680,
            ]);

        $outsideCheck->assertStatus(200)
            ->assertJsonPath('inside_advisory_zone', false)
            ->assertJsonPath('zones_count', 0)
            ->assertJsonPath('matching_zones', [])
            ->assertJsonPath('affected_species', []);
    }

    /**
     * Acceptance Test 8: Changes are recorded in the audit log.
     */
    public function test_changes_are_recorded_in_the_audit_log(): void
    {
        // 1. Create records creation audit log
        $createRes = $this->actingAs($this->reviewer)
            ->postJson('/api/advisory-zones', [
                'title' => '[DEMO] Audit Log Test Zone',
                'concern' => 'Initial concern',
                'classification' => 'suspected',
                'polygon_geojson' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [172.9150, 1.3500],
                        [172.9350, 1.3500],
                        [172.9350, 1.3700],
                        [172.9150, 1.3700],
                        [172.9150, 1.3500],
                    ]],
                ],
            ]);

        $createRes->assertStatus(201);
        $zoneId = $createRes->json('data.id');

        $this->assertDatabaseHas('advisory_audit_logs', [
            'advisory_zone_id' => $zoneId,
            'user_id' => $this->reviewer->id,
            'action' => 'created',
        ]);

        // 2. Update records update audit log
        $updateRes = $this->actingAs($this->admin)
            ->putJson("/api/advisory-zones/{$zoneId}", [
                'concern' => 'Updated laboratory-confirmed dinoflagellate presence',
                'classification' => 'under_investigation',
            ]);

        $updateRes->assertStatus(200);

        $this->assertDatabaseHas('advisory_audit_logs', [
            'advisory_zone_id' => $zoneId,
            'user_id' => $this->admin->id,
            'action' => 'updated',
        ]);

        // 3. Publish records publish audit log
        $publishRes = $this->actingAs($this->admin)
            ->postJson("/api/advisory-zones/{$zoneId}/publish");

        $publishRes->assertStatus(200);

        $this->assertDatabaseHas('advisory_audit_logs', [
            'advisory_zone_id' => $zoneId,
            'user_id' => $this->admin->id,
            'action' => 'published',
        ]);

        // Verify audit logs endpoint returns all 3 logged events
        $logsRes = $this->actingAs($this->admin)
            ->getJson("/api/advisory-zones/{$zoneId}/audit-logs");

        $logsRes->assertStatus(200)
            ->assertJsonPath('count', 3);
    }
}
