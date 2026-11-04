<?php

namespace Tests\Feature;

use App\Models\Species;
use App\Models\User;
use App\Services\MetaAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0-13 — Meta Muse Glimmer Multimodal Vision Fish Species Identification.
 *
 * Covers:
 *  - MetaAiService::identifyFishVision() multimodal prompting and JSON parsing
 *  - Muse Glimmer as primary online classifier in POST /api/ai/identify-fish
 *  - Grounding against Laravel backend species reference catalog
 *  - Graceful fallback to knowledge base on failure, timeout, or unconfigured state
 *  - Meta health endpoint image modality reporting for Muse Glimmer
 */
class MuseGlimmerVisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function glimmerJsonResponse(array $data, int $status = 200): array
    {
        return [
            'id' => 'resp_glimmer_test',
            'status' => 'completed',
            'model' => 'muse-spark-1.3',
            'output' => [
                [
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode($data, JSON_PRETTY_PRINT),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_muse_glimmer_unconfigured_sends_no_network_request(): void
    {
        Http::fake();

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            '', // Unconfigured API key
            5,
            'muse-spark-1.3',
            10
        );

        $result = $service->identifyFishVision('https://example.com/fish.jpg', []);

        $this->assertSame('unconfigured', $result['status']);
        $this->assertSame('muse-spark-1.3', $result['model']);
        $this->assertNull($result['identification']);
        $this->assertStringContainsString('MODEL_API_KEY is not configured', $result['error']);

        Http::assertNothingSent();
    }

    public function test_muse_glimmer_identifies_fish_from_image_payload(): void
    {
        $speciesData = [
            'kiribati_name' => 'Te Okaoka',
            'english_name' => 'Titan Triggerfish',
            'scientific_name' => 'Balistoides viridescens',
            'confidence' => 0.96,
            'visual_features' => [
                'Heavy rhomboidal body with robust beak-like jaws',
                'Dark mustache-like band across upper lip',
                'Erectile locking dorsal trigger spine',
            ],
            'reasoning' => 'Prominent dorsal trigger spine and distinctive facial markings confirm Balistoides viridescens.',
        ];

        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response(
                $this->glimmerJsonResponse($speciesData),
                200
            ),
        ]);

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            'valid_glimmer_test_key',
            5,
            'muse-spark-1.3',
            10
        );

        $refSpecies = [
            ['kiribati_name' => 'Te Okaoka', 'english_name' => 'Titan Triggerfish', 'scientific_name' => 'Balistoides viridescens'],
            ['kiribati_name' => 'Te Bwere', 'english_name' => 'Paddletail Snapper', 'scientific_name' => 'Lutjanus gibbus'],
        ];

        $result = $service->identifyFishVision('https://example.com/test_fish.jpg', $refSpecies);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('muse-spark-1.3', $result['model']);
        $this->assertNotNull($result['identification']);
        $this->assertSame('Te Okaoka', $result['identification']['kiribati_name']);
        $this->assertSame('Titan Triggerfish', $result['identification']['english_name']);
        $this->assertSame('Balistoides viridescens', $result['identification']['scientific_name']);
        $this->assertSame(0.96, $result['identification']['confidence']);
        $this->assertCount(3, $result['identification']['visual_features']);
        $this->assertNull($result['error']);

        Http::assertSent(function ($request) {
            $input = $request['input'][0] ?? [];
            $content = $input['content'] ?? [];

            $promptText = $content[0]['text'] ?? '';
            $imageUrl = is_array($content[1]['image_url'] ?? null)
                ? ($content[1]['image_url']['url'] ?? '')
                : ($content[1]['image_url'] ?? '');

            return $request->url() === 'https://api.meta.ai/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer valid_glimmer_test_key')
                && $request['model'] === 'muse-spark-1.3'
                && $imageUrl === 'https://example.com/test_fish.jpg'
                && str_contains($promptText, 'Te Okaoka')
                && str_contains($promptText, 'Official Kiribati fisheries reference species');
        });
    }

    public function test_muse_glimmer_handles_markdown_wrapped_json_response(): void
    {
        $speciesData = [
            'kiribati_name' => 'Te Ingimea',
            'english_name' => 'Yellowfin Tuna',
            'scientific_name' => 'Thunnus albacares',
            'confidence' => 0.95,
            'visual_features' => ['Bright yellow sickle-shaped dorsal and anal fins'],
            'reasoning' => 'Elongated yellow fins diagnostic of yellowfin tuna.',
        ];

        $markdownText = "```json\n" . json_encode($speciesData) . "\n```";

        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'id' => 'resp_md_test',
                'status' => 'completed',
                'model' => 'muse-spark-1.3',
                'output' => [
                    [
                        'content' => [
                            ['type' => 'output_text', 'text' => $markdownText],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            'test_key',
            5,
            'muse-spark-1.3',
            10
        );

        $result = $service->identifyFishVision('data:image/jpeg;base64,abc123');

        $this->assertSame('completed', $result['status']);
        $this->assertSame('Te Ingimea', $result['identification']['kiribati_name']);
        $this->assertSame('Yellowfin Tuna', $result['identification']['english_name']);
    }

    public function test_identify_endpoint_uses_muse_glimmer_as_primary_classifier(): void
    {
        Config::set('services.meta_ai.api_key', 'test_glimmer_key');

        $glimmerFish = [
            'kiribati_name' => 'Te Bwere',
            'english_name' => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence' => 0.97,
            'visual_features' => [
                'Paddle-shaped rounded caudal tail fin',
                'Deep reddish-pink coloration with steep forehead',
            ],
            'reasoning' => 'Distinct caudal paddle shape observed in photograph.',
        ];

        Http::fake([
            'https://api.meta.ai/*' => function ($request) use ($glimmerFish) {
                $payload = $request->data();
                $model = $payload['model'] ?? '';

                if ($model === 'sam-3.1') {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => '<0f>0<|box;x1=10;y1=10;x2=13;y2=11;w=4;h=1|>']],
                        ]],
                    ], 200);
                }

                if ($model === 'muse-spark-1.3') {
                    return Http::response([
                        'id' => 'resp_glimmer_primary',
                        'status' => 'completed',
                        'model' => 'muse-spark-1.3',
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => json_encode($glimmerFish)]],
                        ]],
                    ], 200);
                }

                // Muse Wonder biological advisory
                return Http::response([
                    'id' => 'resp_wonder',
                    'status' => 'completed',
                    'model' => 'muse-wonder-1.0',
                    'output' => [[
                        'content' => [['type' => 'output_text', 'text' => 'High CFP bioaccumulation risk reported around outer reef drop-offs.']],
                    ]],
                ], 200);
            },
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $response->assertStatus(200);

        // Assert Muse Glimmer primary classification was used in Stage 1
        $response->assertJsonPath('identification.kiribati_name', 'Te Bwere');
        $response->assertJsonPath('identification.english_name', 'Paddletail Snapper');
        $response->assertJsonPath('identification.scientific_name', 'Lutjanus gibbus');
        $response->assertJsonPath('identification.confidence', 0.97);
        $response->assertJsonPath('identification.source', 'db_image_match');
        $response->assertJsonPath('identification.pipeline_stage', 'db_image_match');
        $response->assertJsonPath('identification_pipeline.stage_reached', 1);
        $response->assertJsonPath('identification.meta_model.glimmer_vision.status', 'completed');
        $this->assertStringContainsString('Meta Muse', (string) $response->json('identification.model'));
        $this->assertStringContainsString('muse-spark-1.3', (string) $response->json('identification.model'));

        // Assert SAM evidence and Muse Wonder advisory were also preserved
        $response->assertJsonPath('identification.segmentation.status', 'ok');
        $response->assertJsonPath('identification.meta_model.status', 'completed');
        $this->assertStringContainsString('CFP bioaccumulation', (string) $response->json('identification.meta_model.advisory_notes'));
    }

    public function test_identify_pipeline_runs_stage2_morphological_when_stage1_no_match(): void
    {
        Config::set('services.meta_ai.api_key', 'test_glimmer_key');

        Http::fake([
            'https://api.meta.ai/*' => function ($request) {
                $payload = $request->data();
                $model = $payload['model'] ?? '';

                if ($model === 'sam-3.1') {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => '<0f>0<|box;x1=10;y1=10;x2=13;y2=11;w=4;h=1|>']],
                        ]],
                    ], 200);
                }

                $inputContent = $payload['input'][0]['content'] ?? [];
                $promptText = '';
                foreach ($inputContent as $c) {
                    if (($c['type'] ?? '') === 'text' || ($c['type'] ?? '') === 'input_text') {
                        $promptText .= $c['text'];
                    }
                }

                // Stage 1 yields no match against catalogued photos
                if (str_contains($promptText, 'STAGE 1 — DATABASE PHOTO MATCHING')) {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => json_encode([
                                'matched' => false,
                                'confidence' => 0.40,
                                'reasoning' => 'Does not match any catalogued reference species photos.',
                            ])]],
                        ]],
                    ], 200);
                }

                // Stage 2 matches by morphological traits
                if (str_contains($promptText, 'STAGE 2 — MORPHOLOGICAL TRAITS MATCHING')) {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => json_encode([
                                'matched' => true,
                                'kiribati_name' => 'Te Ingimea',
                                'english_name' => 'Yellowfin Tuna',
                                'scientific_name' => 'Thunnus albacares',
                                'confidence' => 0.88,
                                'visual_features' => ['Bright yellow sickle-shaped dorsal fin', 'Row of vibrant yellow finlets'],
                                'reasoning' => 'Sickle-shaped dorsal and yellow finlets match Thunnus albacares morphological trait profile.',
                            ])]],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'output' => [[
                        'content' => [['type' => 'output_text', 'text' => 'Pelagic advisory notes.']],
                    ]],
                ], 200);
            },
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('identification.kiribati_name', 'Te Ingimea');
        $response->assertJsonPath('identification.english_name', 'Yellowfin Tuna');
        $response->assertJsonPath('identification.source', 'morphological_match');
        $response->assertJsonPath('identification.pipeline_stage', 'morphological_match');
        $response->assertJsonPath('identification_pipeline.stage_reached', 2);
        $response->assertJsonPath('identification_pipeline.stages.db_image_match.status', 'no_match');
        $response->assertJsonPath('identification_pipeline.stages.morphological_match.status', 'completed');
    }

    public function test_identify_pipeline_runs_stage3_web_vision_when_stage1_and_stage2_no_match(): void
    {
        Config::set('services.meta_ai.api_key', 'test_glimmer_key');

        Http::fake([
            'https://api.meta.ai/*' => function ($request) {
                $payload = $request->data();
                $model = $payload['model'] ?? '';

                if ($model === 'sam-3.1') {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => '<0f>0<|box;x1=10;y1=10;x2=13;y2=11;w=4;h=1|>']],
                        ]],
                    ], 200);
                }

                $inputContent = $payload['input'][0]['content'] ?? [];
                $promptText = '';
                foreach ($inputContent as $c) {
                    if (($c['type'] ?? '') === 'text' || ($c['type'] ?? '') === 'input_text') {
                        $promptText .= $c['text'];
                    }
                }

                // Stage 1 fails
                if (str_contains($promptText, 'STAGE 1 — DATABASE PHOTO MATCHING')) {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => json_encode(['matched' => false, 'confidence' => 0.3])]],
                        ]],
                    ], 200);
                }

                // Stage 2 fails
                if (str_contains($promptText, 'STAGE 2 — MORPHOLOGICAL TRAITS MATCHING')) {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => json_encode(['matched' => false, 'confidence' => 0.4])]],
                        ]],
                    ], 200);
                }

                // Stage 3 succeeds with broad web knowledge
                if (str_contains($promptText, 'STAGE 3 — BROAD GLOBAL FISH TAXONOMY')) {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => json_encode([
                                'kiribati_name' => 'Te Rereba',
                                'english_name' => 'Giant Trevally',
                                'scientific_name' => 'Caranx ignobilis',
                                'confidence' => 0.86,
                                'ciguatera_risk' => 'medium',
                                'visual_features' => ['Blunt steep snout profile', 'Heavy scutes along caudal peduncle'],
                                'reasoning' => 'Global taxonomic analysis confirms Caranx ignobilis from steep forehead and lateral scutes.',
                            ])]],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'output' => [[
                        'content' => [['type' => 'output_text', 'text' => 'General advisory.']],
                    ]],
                ], 200);
            },
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('identification.kiribati_name', 'Te Rereba');
        $response->assertJsonPath('identification.source', 'web_vision');
        $response->assertJsonPath('identification.pipeline_stage', 'web_vision');
        $response->assertJsonPath('identification_pipeline.stage_reached', 3);
        $response->assertJsonPath('identification_pipeline.stages.db_image_match.status', 'no_match');
        $response->assertJsonPath('identification_pipeline.stages.morphological_match.status', 'no_match');
        $response->assertJsonPath('identification_pipeline.stages.web_vision.status', 'completed');
    }

    public function test_identify_endpoint_falls_back_to_knowledge_base_when_glimmer_fails(): void
    {
        Config::set('services.meta_ai.api_key', 'test_glimmer_fail_key');

        Http::fake([
            'https://api.meta.ai/*' => function ($request) {
                $payload = $request->data();
                $model = $payload['model'] ?? '';

                if ($model === 'muse-spark-1.3') {
                    // Muse Glimmer throws 500 error
                    return Http::response(['error' => 'Vision server timeout'], 500);
                }

                if ($model === 'sam-3.1') {
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => '<0f>0<|box;x1=10;y1=10;x2=13;y2=11;w=4;h=1|>']],
                        ]],
                    ], 200);
                }

                // Muse Wonder biological advisory
                return Http::response([
                    'output' => [[
                        'content' => [['type' => 'output_text', 'text' => 'Pelagic tuna advisory.']],
                    ]],
                ], 200);
            },
        ]);

        $fisher = User::where('role', 'fisher')->first();
        Sanctum::actingAs($fisher);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
            'hint' => 'Te Ingimea',
        ]);

        // Response must still succeed 200 due to graceful knowledge base fallback!
        $response->assertStatus(200);
        $response->assertJsonPath('identification.kiribati_name', 'Te Ingimea');
        $response->assertJsonPath('identification.english_name', 'Yellowfin Tuna');
        $response->assertJsonPath('identification.meta_model.glimmer_vision.status', 'failed');
        $this->assertStringContainsString('Kiribati Marine Biological Dataset', (string) $response->json('identification.model'));
    }

    public function test_meta_health_reports_muse_glimmer_vision_support(): void
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'id' => 'resp_health_ok',
                'status' => 'completed',
                'model' => 'muse-wonder-1.0',
                'output' => [[
                    'content' => [['text' => 'OK']],
                ]],
            ], 200),
        ]);

        Config::set('services.meta_ai.api_key', 'test_secret_health_token');

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/ai/meta-health');

        $response->assertStatus(200);
        $response->assertJsonPath('image_modality.muse_glimmer_vision_model', 'muse-spark-1.3');
        $response->assertJsonPath('image_modality.muse_glimmer_multimodal_vision', true);
        $response->assertJsonPath('image_modality.muse_glimmer_primary_classifier', true);
        $response->assertJsonPath('image_modality.sam_supports_image', true);
        $response->assertJsonPath('image_modality.muse_wonder_known_text_only', true);
        $this->assertStringNotContainsString('test_secret_health_token', $response->getContent());
    }

    public function test_muse_glimmer_handles_rate_limited_response_429(): void
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'error' => 'Rate limit exceeded. Try again in 60s.',
            ], 429),
        ]);

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            'valid_glimmer_test_key',
            5,
            'muse-spark-1.3',
            10
        );

        $result = $service->identifyFishVision('data:image/jpeg;base64,/9j/4AAQSkZJRg==', []);

        $this->assertSame('rate_limited', $result['status']);
        $this->assertNull($result['identification']);
        $this->assertStringContainsString('Rate limit exceeded', $result['error']);
    }

    public function test_muse_glimmer_handles_invalid_payload_response_400(): void
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'error' => 'input[0].content did not match any supported type',
            ], 400),
        ]);

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            'valid_glimmer_test_key',
            5,
            'muse-spark-1.3',
            10
        );

        $result = $service->identifyFishVision('data:image/jpeg;base64,/9j/4AAQSkZJRg==', []);

        $this->assertSame('invalid_payload', $result['status']);
        $this->assertNull($result['identification']);
        $this->assertStringContainsString('input[0].content did not match any supported type', $result['error']);
    }

    public function test_muse_glimmer_handles_unauthorized_response_401(): void
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'error' => 'Invalid API key provided.',
            ], 401),
        ]);

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            'invalid_key',
            5,
            'muse-spark-1.3',
            10
        );

        $result = $service->identifyFishVision('data:image/jpeg;base64,/9j/4AAQSkZJRg==', []);

        $this->assertSame('unauthorized', $result['status']);
        $this->assertNull($result['identification']);
        $this->assertStringContainsString('Invalid API key', $result['error']);
    }

    public function test_muse_glimmer_preserves_honest_confidence_score(): void
    {
        $speciesData = [
            'kiribati_name' => 'Te Ikamban',
            'english_name' => 'Bonefish',
            'scientific_name' => 'Albula glossodonta',
            'confidence' => 0.63,
            'visual_features' => ['Conical snout overhang', 'Deep silver sides'],
            'reasoning' => 'Low-confidence bonefish candidate based on visible snout shape.',
        ];

        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response(
                $this->glimmerJsonResponse($speciesData),
                200
            ),
        ]);

        $service = new MetaAiService(
            'https://api.meta.ai/v1/responses',
            'muse-wonder-1.0',
            'valid_key',
            5,
            'muse-spark-1.3',
            10
        );

        $result = $service->identifyFishVision('data:image/jpeg;base64,/9j/4AAQSkZJRg==', []);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(0.63, $result['identification']['confidence']);
        $this->assertSame('Albula glossodonta', $result['identification']['scientific_name']);
    }
}
