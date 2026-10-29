<?php

namespace Tests\Feature;

use App\Models\CatchRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatchIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['services.meta_ai.api_key' => null]);
    }

    public function test_same_client_uuid_retry_returns_one_row(): void
    {
        $fisher = User::where('role', 'fisher')->first();
        $payload = [
            'client_uuid' => '11111111-2222-4333-8444-555555555555',
            'species_name_raw' => 'Te Bwere',
            'weight_kg' => 2.5,
            'quantity' => 1,
            'latitude' => 1.334,
            'longitude' => 172.984,
            'captured_at' => '2026-10-28T05:30:00Z',
        ];

        $first = $this->actingAs($fisher, 'sanctum')->postJson('/api/catches', $payload);
        $first->assertStatus(201);

        $second = $this->actingAs($fisher, 'sanctum')->postJson('/api/catches', $payload);
        $second->assertStatus(200);
        $second->assertJsonPath('idempotent_replay', true);
        $second->assertJsonPath('catch.id', $first->json('catch.id'));
        $second->assertJsonPath('catch.client_uuid', $payload['client_uuid']);

        $this->assertSame(1, CatchRecord::where('client_uuid', $payload['client_uuid'])->count());
        $row = CatchRecord::where('client_uuid', $payload['client_uuid'])->first();
        $this->assertSame('Te Bwere', $row->ai_species_suggestion);
        $this->assertSame('2026-10-28 05:30:00', $row->caught_at->format('Y-m-d H:i:s'));
    }

    public function test_without_client_uuid_creates_still_work(): void
    {
        $fisher = User::where('role', 'fisher')->first();
        $payload = ['species_name_raw' => 'Te Atun', 'weight_kg' => 1.0];

        $before = CatchRecord::whereNull('client_uuid')->where('user_id', $fisher->id)->count();
        $this->actingAs($fisher, 'sanctum')->postJson('/api/catches', $payload)->assertStatus(201);
        $this->actingAs($fisher, 'sanctum')->postJson('/api/catches', $payload)->assertStatus(201);

        $this->assertSame($before + 2, CatchRecord::whereNull('client_uuid')->where('user_id', $fisher->id)->count());
    }

    public function test_client_uuid_owned_by_another_fisher_conflicts(): void
    {
        $fishers = User::where('role', 'fisher')->limit(2)->get();
        $payload = ['client_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'weight_kg' => 1.0];

        $this->actingAs($fishers[0], 'sanctum')->postJson('/api/catches', $payload)->assertStatus(201);
        $conflict = $this->actingAs($fishers[1], 'sanctum')->postJson('/api/catches', $payload);
        $conflict->assertStatus(409);
        $conflict->assertJsonPath('error', 'CLIENT_UUID_CONFLICT');

        $this->assertSame(1, CatchRecord::where('client_uuid', $payload['client_uuid'])->count());
    }

    public function test_missing_gps_stays_null_and_provided_gps_is_preserved(): void
    {
        $fisher = User::where('role', 'fisher')->first();

        $noGps = $this->actingAs($fisher, 'sanctum')->postJson('/api/catches', [
            'client_uuid' => '00000000-1111-4222-8333-444444444444',
            'weight_kg' => 1.5,
        ]);
        $noGps->assertStatus(201);
        $this->assertNull($noGps->json('catch.latitude'));
        $this->assertNull($noGps->json('catch.longitude'));
        $this->assertDatabaseHas('catch_records', [
            'client_uuid' => '00000000-1111-4222-8333-444444444444',
            'latitude' => null,
            'longitude' => null,
        ]);

        $withGps = $this->actingAs($fisher, 'sanctum')->postJson('/api/catches', [
            'client_uuid' => '99999999-8888-4777-8666-555555555555',
            'weight_kg' => 1.5,
            'latitude' => 1.365,
            'longitude' => 172.945,
        ]);
        $withGps->assertStatus(201);
        $this->assertEqualsWithDelta(1.365, $withGps->json('catch.latitude'), 0.000001);
        $this->assertEqualsWithDelta(172.945, $withGps->json('catch.longitude'), 0.000001);
    }

    public function test_out_of_range_gps_is_rejected(): void
    {
        $fisher = User::where('role', 'fisher')->first();

        $this->actingAs($fisher, 'sanctum')->postJson('/api/catches', [
            'latitude' => 91.0,
            'longitude' => 172.0,
        ])->assertStatus(422);

        $this->actingAs($fisher, 'sanctum')->postJson('/api/catches', [
            'latitude' => 1.0,
            'longitude' => 181.0,
        ])->assertStatus(422);
    }
}
