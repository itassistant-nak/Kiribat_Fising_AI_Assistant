<?php

namespace Tests\Feature;

use App\Models\FishingTrip;
use App\Models\TripRoutePoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripRouteSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $fisher;
    protected User $otherFisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fisher = User::create([
            'name' => 'Teititeki Tabua',
            'email' => 'teititeki@fisher.ki',
            'password' => bcrypt('FisherPass2026!'),
            'role' => 'fisher',
        ]);

        $this->otherFisher = User::create([
            'name' => 'Biraati Teuea',
            'email' => 'biraati@fisher.ki',
            'password' => bcrypt('FisherPass2026!'),
            'role' => 'fisher',
        ]);
    }

    public function test_fisher_can_create_trip(): void
    {
        Sanctum::actingAs($this->fisher);

        $response = $this->postJson('/api/trips', [
            'vessel_name' => 'Te Raoi II',
            'started_at' => now()->toIso8601String(),
            'notes' => 'Morning lagoon run',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user_id', $this->fisher->id)
            ->assertJsonPath('data.vessel_name', 'Te Raoi II')
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('fishing_trips', [
            'user_id' => $this->fisher->id,
            'vessel_name' => 'Te Raoi II',
        ]);
    }

    public function test_fisher_can_upload_route_points_batch(): void
    {
        Sanctum::actingAs($this->fisher);

        $trip = FishingTrip::create([
            'user_id' => $this->fisher->id,
            'vessel_name' => 'Te Raoi II',
            'started_at' => now()->subHours(2),
            'status' => 'in_progress',
        ]);

        $response = $this->postJson("/api/trips/{$trip->id}/route-points", [
            'points' => [
                ['latitude' => 1.3300, 'longitude' => 172.9800, 'sequence' => 0, 'recorded_at' => now()->subHours(2)->toIso8601String()],
                ['latitude' => 1.3350, 'longitude' => 172.9850, 'sequence' => 1, 'recorded_at' => now()->subHours(1)->toIso8601String()],
                ['latitude' => 1.3400, 'longitude' => 172.9900, 'sequence' => 2, 'recorded_at' => now()->toIso8601String()],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('trip_id', $trip->id)
            ->assertJsonPath('synced_points', 3);

        // Ordered polyline retrievable via existing route endpoint
        $route = $this->getJson("/api/trips/{$trip->id}/route");
        $route->assertStatus(200);
        $points = $route->json('route_points');
        $this->assertCount(3, $points);
        $this->assertEquals([0, 1, 2], array_column($points, 'sequence'));
        $this->assertEquals(1.34, $points[2]['latitude']);
    }

    public function test_route_point_upload_rejects_invalid_coordinates(): void
    {
        Sanctum::actingAs($this->fisher);

        $trip = FishingTrip::create([
            'user_id' => $this->fisher->id,
            'started_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        $response = $this->postJson("/api/trips/{$trip->id}/route-points", [
            'points' => [
                ['latitude' => 95.0, 'longitude' => 172.98, 'sequence' => 0, 'recorded_at' => now()->toIso8601String()],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(0, TripRoutePoint::where('trip_id', $trip->id)->count());
    }

    public function test_fisher_cannot_upload_points_to_another_fishers_trip(): void
    {
        $trip = FishingTrip::create([
            'user_id' => $this->otherFisher->id,
            'started_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($this->fisher);

        $response = $this->postJson("/api/trips/{$trip->id}/route-points", [
            'points' => [
                ['latitude' => 1.33, 'longitude' => 172.98, 'sequence' => 0, 'recorded_at' => now()->toIso8601String()],
            ],
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'GPS_PROTECTED');
    }

    public function test_fisher_can_end_trip(): void
    {
        Sanctum::actingAs($this->fisher);

        $trip = FishingTrip::create([
            'user_id' => $this->fisher->id,
            'started_at' => now()->subHours(3),
            'status' => 'in_progress',
        ]);

        $response = $this->patchJson("/api/trips/{$trip->id}", [
            'ended_at' => now()->toIso8601String(),
            'status' => 'completed',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertEquals('completed', $trip->fresh()->status);
    }

    public function test_route_point_upload_is_idempotent_on_retry(): void
    {
        Sanctum::actingAs($this->fisher);

        $trip = FishingTrip::create([
            'user_id' => $this->fisher->id,
            'started_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        $payload = [
            'points' => [
                ['latitude' => 1.33, 'longitude' => 172.98, 'sequence' => 0, 'recorded_at' => now()->subHour()->toIso8601String()],
                ['latitude' => 1.34, 'longitude' => 172.99, 'sequence' => 1, 'recorded_at' => now()->toIso8601String()],
            ],
        ];

        $this->postJson("/api/trips/{$trip->id}/route-points", $payload)->assertStatus(201);
        // Retry (e.g. after a network timeout): must not duplicate points
        $this->postJson("/api/trips/{$trip->id}/route-points", $payload)->assertStatus(201);

        $this->assertEquals(2, TripRoutePoint::where('trip_id', $trip->id)->count());
    }
}
