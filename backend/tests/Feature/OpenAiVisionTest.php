<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpenAiVisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the OpenAI Vision API Stage 4 integration.
 *
 * All tests use Http::fake() so zero real API calls are made.
 * Tests verify:
 *   1. Unconfigured service returns 'unconfigured' without HTTP calls
 *   2. Successful vision response is correctly parsed
 *   3. Stage 4 runs as pipeline fallback after Meta+local matching
 *   4. API failure degrades gracefully (no exception, fallback continues)
 */
class OpenAiVisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: build a well-formed OpenAI chat completions response
    // ─────────────────────────────────────────────────────────────────────────
    private function buildOpenAiResponse(array $identification): array
    {
        return [
            'id'     => 'chatcmpl-test-123',
            'object' => 'chat.completion',
            'choices' => [
                [
                    'index'        => 0,
                    'message'      => [
                        'role'    => 'assistant',
                        'content' => json_encode($identification),
                    ],
                    'finish_reason'=> 'stop',
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1: Unconfigured — no HTTP call, returns 'unconfigured'
    // ─────────────────────────────────────────────────────────────────────────
    public function test_openai_vision_service_unconfigured_sends_no_request(): void
    {
        // Force key to empty
        config(['services.openai.api_key' => null]);
        Http::fake();

        $service = new OpenAiVisionService();

        $this->assertFalse($service->isConfigured());

        $result = $service->identifyFishVision('https://example.com/fish.jpg');

        $this->assertEquals('unconfigured', $result['status']);
        $this->assertNull($result['identification']);
        $this->assertEquals(0, $result['latency_ms']);

        Http::assertNothingSent();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2: Configured — parses structured identification from API response
    // ─────────────────────────────────────────────────────────────────────────
    public function test_openai_vision_service_parses_identification_from_response(): void
    {
        config(['services.openai.api_key' => 'sk-test-key-for-unit-test']);

        $mockIdent = [
            'kiribati_name'  => 'Te Bwere',
            'english_name'   => 'Paddletail Snapper',
            'scientific_name'=> 'Lutjanus gibbus',
            'confidence'     => 0.91,
            'ciguatera_risk' => 'high',
            'visual_features'=> ['reddish-pink body', 'rounded caudal fin'],
            'reasoning'      => 'Distinctive paddle-shaped caudal fin and reddish coloration match Lutjanus gibbus.',
        ];

        Http::fake([
            'api.openai.com/*' => Http::response($this->buildOpenAiResponse($mockIdent), 200),
        ]);

        $service = new OpenAiVisionService();

        $this->assertTrue($service->isConfigured());

        $result = $service->identifyFishVision(
            'https://example.com/te_bwere.jpg',
            [['kiribati_name' => 'Te Bwere', 'english_name' => 'Paddletail Snapper', 'scientific_name' => 'Lutjanus gibbus']],
            'Te Bwere'
        );

        $this->assertEquals('completed', $result['status']);
        $this->assertIsArray($result['identification']);
        $this->assertEquals('Te Bwere', $result['identification']['kiribati_name']);
        $this->assertEquals('Paddletail Snapper', $result['identification']['english_name']);
        $this->assertEquals('Lutjanus gibbus', $result['identification']['scientific_name']);
        $this->assertEquals(0.91, $result['identification']['confidence']);
        $this->assertEquals('high', $result['identification']['ciguatera_risk']);
        $this->assertNotEmpty($result['identification']['visual_features']);
        $this->assertNotEmpty($result['identification']['reasoning']);

        Http::assertSentCount(1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3: Stage 4 runs as pipeline fallback in the full identify-fish flow
    //         when Meta is unconfigured and local KB matching finds nothing
    // ─────────────────────────────────────────────────────────────────────────
    public function test_identify_fish_runs_stage4_openai_when_meta_unconfigured_and_no_local_match(): void
    {
        // Disable Meta AI so Stages 1-3 are skipped
        config(['services.meta_ai.api_key' => null]);
        // Enable OpenAI with a fake key
        config(['services.openai.api_key' => 'sk-test-key-for-pipeline-test']);

        $mockIdent = [
            'kiribati_name'  => 'Te Ingimea',
            'english_name'   => 'Yellowfin Tuna',
            'scientific_name'=> 'Thunnus albacares',
            'confidence'     => 0.88,
            'ciguatera_risk' => 'low',
            'visual_features'=> ['bright yellow second dorsal fin', 'metallic blue dorsal'],
            'reasoning'      => 'Yellow second dorsal fin and sickle-shape match Thunnus albacares.',
        ];

        Http::fake([
            'api.openai.com/*' => Http::response($this->buildOpenAiResponse($mockIdent), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        // Use image_url without a hint so local KB matching won't pre-empt Stage 4
        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/tuna_unknown.jpg',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('identification.source', 'openai_vision')
                 ->assertJsonPath('identification.pipeline_stage', 'openai_vision')
                 ->assertJsonPath('identification.pipeline.stage_reached', 4)
                 ->assertJsonPath('identification.pipeline.stages.openai_vision.attempted', true)
                 ->assertJsonPath('identification.pipeline.stages.openai_vision.status', 'completed')
                 ->assertJsonPath('identification.kiribati_name', 'Te Ingimea')
                 ->assertJsonPath('identification.openai_model.configured', true)
                 ->assertJsonPath('identification.openai_model.model', 'gpt-4o');

        Http::assertSentCount(1); // Only Stage 4 OpenAI call
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4: API failure — Stage 4 fails gracefully; deterministic KB fallback
    //         still returns a valid result without throwing exceptions
    // ─────────────────────────────────────────────────────────────────────────
    public function test_identify_fish_degrades_gracefully_when_openai_api_fails(): void
    {
        config(['services.meta_ai.api_key' => null]);
        config(['services.openai.api_key' => 'sk-test-key-for-failure-test']);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/unknown_fish.jpg',
        ]);

        // Must return 200 with honest unavailable status (no forced KB fallback)
        $response->assertStatus(200)
                 ->assertJsonPath('status', 'unavailable')
                 ->assertJsonStructure(['identification' => ['kiribati_name', 'english_name', 'source']]);

        // Stage 4 must show attempted=true and failed status
        $pipeline = $response->json('identification.pipeline.stages.openai_vision');
        $this->assertTrue($pipeline['attempted']);
        $this->assertEquals('failed', $pipeline['status']);

        // Source should be insufficient_evidence
        $this->assertEquals('insufficient_evidence', $response->json('identification.source'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5: OpenAiFishVisionService maps 429 insufficient_quota to quota_exceeded
    // ─────────────────────────────────────────────────────────────────────────
    public function test_openai_fish_vision_service_handles_quota_exceeded_429(): void
    {
        $service = new \App\Services\OpenAiFishVisionService(
            'https://api.openai.com/v1/chat/completions',
            'gpt-4o',
            'sk-test-key-quota',
            10,
            true
        );
        $service->clearQuotaExceeded();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'You exceeded your current quota, please check your plan and billing details.',
                    'type'    => 'insufficient_quota',
                    'code'    => 'insufficient_quota',
                ],
            ], 429),
        ]);

        $result = $service->identifyFishVision('https://example.com/test.jpg');

        $this->assertSame('quota_exceeded', $result['status']);
        $this->assertNull($result['identification']);
        $this->assertTrue($service->isQuotaExceeded());
        $this->assertStringContainsString('quota', strtolower($result['error']));

        // Verify short-circuit prevents repeated network requests
        Http::fake();
        $secondResult = $service->identifyFishVision('https://example.com/test2.jpg');
        $this->assertSame('quota_exceeded', $secondResult['status']);
        Http::assertNothingSent();

        // Cleanup
        $service->clearQuotaExceeded();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6: OpenAiFishVisionService distinguishes 401 unauthorized from quota
    // ─────────────────────────────────────────────────────────────────────────
    public function test_openai_fish_vision_service_distinguishes_unauthorized_from_quota(): void
    {
        $service = new \App\Services\OpenAiFishVisionService(
            'https://api.openai.com/v1/chat/completions',
            'gpt-4o',
            'sk-invalid-key',
            10,
            true
        );
        $service->clearQuotaExceeded();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'Incorrect API key provided.',
                    'type'    => 'invalid_request_error',
                    'code'    => 'invalid_api_key',
                ],
            ], 401),
        ]);

        $result = $service->identifyFishVision('https://example.com/test.jpg');

        $this->assertSame('unauthorized', $result['status']);
        $this->assertFalse($service->isQuotaExceeded());
        $this->assertStringContainsString('Incorrect API key', $result['error']);

        $service->clearQuotaExceeded();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 7: OpenAiFishVisionService distinguishes rate limiting from quota
    // ─────────────────────────────────────────────────────────────────────────
    public function test_openai_fish_vision_service_distinguishes_rate_limiting(): void
    {
        $service = new \App\Services\OpenAiFishVisionService(
            'https://api.openai.com/v1/chat/completions',
            'gpt-4o',
            'sk-test-key-rate-limit',
            10,
            true
        );
        $service->clearQuotaExceeded();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'Rate limit reached for requests per minute.',
                    'type'    => 'requests',
                    'code'    => 'rate_limit_exceeded',
                ],
            ], 429),
        ]);

        $result = $service->identifyFishVision('https://example.com/test.jpg');

        $this->assertSame('rate_limited', $result['status']);
        $this->assertFalse($service->isQuotaExceeded());
        $this->assertStringContainsString('Rate limit reached', $result['error']);

        $service->clearQuotaExceeded();
    }
}
