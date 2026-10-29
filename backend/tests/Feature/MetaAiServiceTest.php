<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Species;
use App\Models\CatchRecord;
use App\Services\MetaAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class MetaAiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_meta_ai_service_text_query_succeeds_with_completed_status()
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'id' => 'resp_test_abc123',
                'status' => 'completed',
                'model' => 'muse-wonder-1.0',
                'output' => [
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Connectivity test OK from Muse Wonder.',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            'test_secret_meta_api_key_value_xyz',
            10
        );

        $result = $service->queryText('Respond with OK for Kiribati fisheries assistant connectivity test.');

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals('muse-wonder-1.0', $result['model']);
        $this->assertEquals('Connectivity test OK from Muse Wonder.', $result['text']);
        $this->assertNull($result['error']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);

        // Assert request was sent with Bearer auth and correct JSON body
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.meta.ai/v1/responses' &&
                   $request->hasHeader('Authorization', 'Bearer test_secret_meta_api_key_value_xyz') &&
                   $request['model'] === 'muse-wonder-1.0';
        });
    }

    public function test_meta_ai_service_unconfigured_handles_missing_key_gracefully()
    {
        Http::fake();

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            '', // Explicitly no API key (env-independent: constructor treats '' as unconfigured)
            10
        );

        $this->assertFalse($service->isConfigured());

        $result = $service->queryText('Ping');

        $this->assertEquals('unconfigured', $result['status']);
        $this->assertNull($result['text']);
        $this->assertStringContainsString('MODEL_API_KEY is not configured', $result['error']);

        // No network call should have been attempted
        Http::assertNothingSent();
    }

    public function test_meta_ai_service_handles_api_errors_and_timeouts()
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'error' => [
                    'message' => 'Rate limit exceeded on Muse Wonder model',
                    'type' => 'rate_limit_error',
                ],
            ], 429),
        ]);

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            'mock_key',
            5
        );

        $result = $service->queryText('Hello');

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals(429, $result['status_code']);
        $this->assertStringContainsString('Rate limit exceeded', $result['error']);
        $this->assertNull($result['text']);
    }

    public function test_meta_health_endpoint_requires_authentication()
    {
        $response = $this->getJson('/api/ai/meta-health');
        $response->assertStatus(401);
    }

    public function test_meta_health_endpoint_forbidden_for_regular_fishers()
    {
        $fisher = User::where('role', 'fisher')->first();

        $response = $this->actingAs($fisher, 'sanctum')->getJson('/api/ai/meta-health');
        $response->assertStatus(403);
    }

    public function test_meta_health_endpoint_accessible_to_admin_and_reviewer()
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'id' => 'resp_health_test',
                'status' => 'completed',
                'model' => 'muse-wonder-1.0',
                'output' => [
                    [
                        'content' => [
                            ['text' => 'OK'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        Config::set('services.meta_ai.api_key', 'super_secret_test_token_123');

        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/ai/meta-health');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'endpoint',
            'model',
            'has_api_key',
            'latency_ms',
            'response',
            'message',
            'tested_at',
            'disclaimer',
        ]);

        $data = $response->json();
        $this->assertEquals('connected', $data['status']);
        $this->assertEquals('muse-wonder-1.0', $data['model']);
        $this->assertTrue($data['has_api_key']);
        $this->assertEquals('OK', $data['response']);

        // Assert API key is NEVER leaked anywhere in the JSON response
        $this->assertStringNotContainsString('super_secret_test_token_123', $response->getContent());
    }

    public function test_meta_health_endpoint_returns_unconfigured_when_key_is_missing()
    {
        Config::set('services.meta_ai.api_key', null);

        $reviewer = User::where('role', 'reviewer')->first();

        $response = $this->actingAs($reviewer, 'sanctum')->getJson('/api/ai/meta-health');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('unconfigured', $data['status']);
        $this->assertFalse($data['has_api_key']);
        $this->assertNull($data['response']);
        $this->assertStringContainsString('MODEL_API_KEY is not configured', $data['message']);
    }

    public function test_ai_identification_integrates_meta_service_preserving_morphological_fallbacks()
    {
        Http::fake([
            // Photo download for SAM evidence must also be faked (no real network)
            'https://images.unsplash.com/*' => Http::response(
                base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AvwEf/9k='),
                200,
                ['Content-Type' => 'image/jpeg']
            ),
            'https://api.meta.ai/v1/responses' => Http::response([
                'id' => 'resp_adv_123',
                'status' => 'completed',
                'output' => [
                    [
                        'content' => [
                            ['text' => 'Pelagic predator; highly migratory in central Pacific waters.'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        Config::set('services.meta_ai.api_key', 'mock_valid_key');

        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700',
            'hint' => 'Te Ingimea',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('identification.kiribati_name', 'Te Ingimea');
        $response->assertJsonPath('identification.english_name', 'Yellowfin Tuna');
        $response->assertJsonPath('identification.meta_model.configured', true);
        $this->assertNotEmpty($response->json('identification.visual_features'));
    }

    public function test_catch_registration_preserves_human_ground_truth_with_ai_suggestion()
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/catches', [
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700',
            'english_name' => 'Titan Triggerfish',
            'kiribati_name' => 'Te Okaoka',
            'scientific_name' => 'Balistoides viridescens',
            'ai_species_suggestion' => 'Te Okaoka (Titan Triggerfish)',
            'ai_confidence' => 0.95,
            'weight_kg' => 4.2,
            'quantity' => 1,
            'verification_notes' => 'Confirmed ground truth after reviewing AI visual traits.',
            'verification_status' => 'verified',
        ]);

        $response->assertStatus(201);

        // Verify that original AI suggestion was immutably stored alongside human ground truth
        $this->assertDatabaseHas('catch_records', [
            'ai_species_suggestion' => 'Te Okaoka (Titan Triggerfish)',
            'verification_status' => 'verified',
            'verified_by_user_id' => $admin->id,
        ]);
    }
}
