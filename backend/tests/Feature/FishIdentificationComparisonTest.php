<?php

namespace Tests\Feature;

use App\Models\CatchRecord;
use App\Models\FishIdentificationProvider;
use App\Models\Species;
use App\Models\User;
use App\Services\FishIdentificationComparisonService;
use App\Services\MetaAiService;
use App\Services\OpenAiFishVisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Tests for Parallel AI Fish Identification (Meta Muse Glimmer + OpenAI Vision).
 *
 * Verifies Phase 8 requirements:
 * 1. Both providers return the same species (agreement)
 * 2. Providers return different species (disagreement)
 * 3. OpenAI succeeds and Muse Glimmer fails (single_provider_result)
 * 4. Muse Glimmer succeeds and OpenAI fails (single_provider_result)
 * 5. Both providers fail (both_failed)
 * 6. One provider returns malformed JSON (handled gracefully)
 * 7. Species is not in the Kiribati catalogue (preserved without fabricating records)
 * 8. Scientific-name matching (case-insensitive binomial comparison)
 * 9. Provider confidence values remain separate (never averaged)
 * 10. Human verification remains required (advisory only)
 * 11. API key is never returned or logged
 * 12. Database persistence of provider logs and metrics
 */
class FishIdentificationComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function mockMuseResponse(array $data, int $status = 200): array
    {
        return [
            'id'     => 'resp_muse_test',
            'status' => 'completed',
            'model'  => 'muse-spark-1.3',
            'output' => [
                [
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode($data),
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function mockOpenAiResponse(array $data, int $status = 200): array
    {
        return [
            'id'      => 'chatcmpl_openai_test',
            'object'  => 'chat.completion',
            'model'   => 'gpt-4o',
            'choices' => [
                [
                    'index'   => 0,
                    'message' => [
                        'role'    => 'assistant',
                        'content' => json_encode($data),
                    ],
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1: Both providers return the same species -> agreement
    // ─────────────────────────────────────────────────────────────────────────
    public function test_both_providers_return_same_species_results_in_agreement(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Bwere',
            'english_name'    => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence'      => 0.94,
            'visual_features' => ['Paddle-shaped caudal fin', 'Reddish-pink body'],
            'reasoning'       => 'Deep paddle-shaped tail and red body indicate Lutjanus gibbus.',
        ];

        $openAiData = [
            'kiribati_name'   => 'Te Bwere',
            'common_name'     => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence'      => 0.88,
            'visible_traits'  => ['Deep red-pink coloration', 'Distinct paddle tail'],
            'reasoning'       => 'Caudal fin paddle margin diagnostic of Lutjanus gibbus.',
            'alternatives'    => [
                ['scientific_name' => 'Lutjanus bohar', 'common_name' => 'Two-spot Red Snapper', 'confidence' => 0.40],
            ],
            'ciguatera_risk'  => 'high',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/te_bwere.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'agreement');
        $response->assertJsonPath('comparison.scientific_name_match', true);
        $response->assertJsonPath('comparison.catalogue_match', true);
        $response->assertJsonPath('comparison.human_confirmation_required', true);
        $response->assertJsonPath('providers.muse_glimmer.status', 'success');
        $response->assertJsonPath('providers.muse_glimmer.confidence', 0.94);
        $response->assertJsonPath('providers.openai.status', 'success');
        $response->assertJsonPath('providers.openai.confidence', 0.88);
        $this->assertNotEquals(
            $response->json('providers.muse_glimmer.confidence'),
            $response->json('providers.openai.confidence')
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2: Providers return different species -> disagreement
    // ─────────────────────────────────────────────────────────────────────────
    public function test_providers_return_different_species_results_in_disagreement(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Bwere',
            'english_name'    => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence'      => 0.92,
            'visual_features' => ['Paddle-shaped caudal fin'],
            'reasoning'       => 'Tail shape indicates Lutjanus gibbus.',
        ];

        $openAiData = [
            'kiribati_name'   => 'Te Baibo',
            'common_name'     => 'Peacock Grouper',
            'scientific_name' => 'Cephalopholis argus',
            'confidence'      => 0.85,
            'visible_traits'  => ['Dark brown body with blue spots'],
            'reasoning'       => 'Blue ringed ocelli match Cephalopholis argus.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'high',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/ambiguous_specimen.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'disagreement');
        $response->assertJsonPath('comparison.scientific_name_match', false);
        $response->assertJsonPath('comparison.human_confirmation_required', true);
        $response->assertJsonPath('providers.muse_glimmer.scientific_name', 'Lutjanus gibbus');
        $response->assertJsonPath('providers.openai.scientific_name', 'Cephalopholis argus');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3: OpenAI succeeds and Muse Glimmer fails -> single_provider_result
    // ─────────────────────────────────────────────────────────────────────────
    public function test_openai_succeeds_and_muse_glimmer_fails(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $openAiData = [
            'kiribati_name'   => 'Te Ingimea',
            'common_name'     => 'Yellowfin Tuna',
            'scientific_name' => 'Thunnus albacares',
            'confidence'      => 0.91,
            'visible_traits'  => ['Sickle-shaped bright yellow dorsal and anal fins'],
            'reasoning'       => 'Yellow finlets and sickle fins confirm Thunnus albacares.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'low',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response(['error' => 'Model service 500 error'], 500),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/yellowfin.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'single_provider_result');
        $response->assertJsonPath('providers.muse_glimmer.status', 'failed');
        $response->assertJsonPath('providers.openai.status', 'success');
        $response->assertJsonPath('providers.openai.scientific_name', 'Thunnus albacares');
        $response->assertJsonPath('comparison.human_confirmation_required', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4: Muse Glimmer succeeds and OpenAI fails -> single_provider_result
    // ─────────────────────────────────────────────────────────────────────────
    public function test_muse_glimmer_succeeds_and_openai_fails(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Okaoka',
            'english_name'    => 'Titan Triggerfish',
            'scientific_name' => 'Balistoides viridescens',
            'confidence'      => 0.95,
            'visual_features' => ['Locking trigger spine', 'Rhomboid body'],
            'reasoning'       => 'Erectile trigger spine identifies Balistoides viridescens.',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/triggerfish.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'single_provider_result');
        $response->assertJsonPath('providers.muse_glimmer.status', 'success');
        $response->assertJsonPath('providers.muse_glimmer.scientific_name', 'Balistoides viridescens');
        $this->assertContains($response->json('providers.openai.status'), ['failed', 'rate_limited']);
        $response->assertJsonPath('comparison.human_confirmation_required', true);
    }

    public function test_muse_glimmer_succeeds_and_openai_quota_exceeded(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Okaoka',
            'english_name'    => 'Titan Triggerfish',
            'scientific_name' => 'Balistoides viridescens',
            'confidence'      => 0.95,
            'visual_features' => ['Locking trigger spine', 'Rhomboid body'],
            'reasoning'       => 'Erectile trigger spine identifies Balistoides viridescens.',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response([
                'error' => [
                    'message' => 'You exceeded your current quota, please check your plan and billing details.',
                    'type'    => 'insufficient_quota',
                    'code'    => 'insufficient_quota',
                ],
            ], 429),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/triggerfish.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'single_provider_result');
        $response->assertJsonPath('providers.muse_glimmer.status', 'success');
        $response->assertJsonPath('providers.muse_glimmer.scientific_name', 'Balistoides viridescens');
        $response->assertJsonPath('providers.openai.status', 'quota_exceeded');
        $this->assertNull($response->json('providers.openai.confidence'));
        $this->assertStringContainsString('quota exceeded', (string) $response->json('providers.openai.reasoning'));
        $response->assertJsonPath('comparison.human_confirmation_required', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5: Both providers fail -> both_failed
    // ─────────────────────────────────────────────────────────────────────────
    public function test_both_providers_fail_results_in_both_failed(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        Http::fake([
            'https://api.meta.ai/*' => Http::response(['error' => 'Meta timeout'], 504),
            'api.openai.com/*'      => Http::response(['error' => ['message' => 'OpenAI gateway timeout']], 504),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/broken_connection.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'both_failed');
        $this->assertContains($response->json('providers.muse_glimmer.status'), ['failed', 'timeout']);
        $this->assertContains($response->json('providers.openai.status'), ['failed', 'timeout']);
        $response->assertJsonPath('comparison.human_confirmation_required', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6: One provider returns malformed JSON -> handled gracefully
    // ─────────────────────────────────────────────────────────────────────────
    public function test_one_provider_returns_malformed_json_handled_gracefully(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Ikari',
            'english_name'    => 'Bonefish',
            'scientific_name' => 'Albula glossodonta',
            'confidence'      => 0.90,
            'visual_features' => ['Conical downturned snout'],
            'reasoning'       => 'Snout structure matches Albula glossodonta.',
        ];

        // OpenAI returns corrupted/non-JSON text
        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'I could not format this as JSON: {broken json ...',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/bonefish.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'single_provider_result');
        $response->assertJsonPath('providers.muse_glimmer.status', 'success');
        $response->assertJsonPath('providers.muse_glimmer.scientific_name', 'Albula glossodonta');
        $response->assertJsonPath('providers.openai.status', 'invalid_response');
        $this->assertNotNull($response->json('providers.openai.error'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 7: Species is not in the Kiribati catalogue -> preserved without creating fake DB rows
    // ─────────────────────────────────────────────────────────────────────────
    public function test_species_not_in_catalogue_preserved_without_inventing_db_records(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $initialSpeciesCount = Species::count();

        // Amphiprion ocellaris (Clown Anemonefish) - not in seeded Kiribati catalogue
        $unlistedSpecies = 'Amphiprion ocellaris';
        $this->assertNull(Species::where('scientific_name', $unlistedSpecies)->first());

        $museData = [
            'kiribati_name'   => 'Unknown Fish',
            'english_name'    => 'Clown Anemonefish',
            'scientific_name' => $unlistedSpecies,
            'confidence'      => 0.89,
            'visual_features' => ['Bright orange body with three vertical white bands'],
            'reasoning'       => 'Coloration and bands indicate Amphiprion ocellaris.',
        ];

        $openAiData = [
            'kiribati_name'   => null,
            'common_name'     => 'Clown Anemonefish',
            'scientific_name' => $unlistedSpecies,
            'confidence'      => 0.86,
            'visible_traits'  => ['Three white bars with thin black margins'],
            'reasoning'       => 'Anemonefish morphology matches Amphiprion ocellaris.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'low',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/convict_surgeonfish.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        // Agreement on uncatalogued species
        $response->assertJsonPath('comparison_status', 'agreement');
        $response->assertJsonPath('comparison.scientific_name_match', true);
        $response->assertJsonPath('providers.muse_glimmer.catalogue_match', false);
        $response->assertJsonPath('providers.openai.catalogue_match', false);
        $response->assertJsonPath('providers.muse_glimmer.scientific_name', $unlistedSpecies);
        $response->assertJsonPath('providers.openai.scientific_name', $unlistedSpecies);

        // Assert database species count remains UNCHANGED (no fake record fabricated)
        $this->assertSame($initialSpeciesCount, Species::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 8: Scientific-name matching with casing and whitespace normalization
    // ─────────────────────────────────────────────────────────────────────────
    public function test_scientific_name_matching_normalizes_casing_and_whitespace(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Kuau',
            'english_name'    => 'Blacktip Grouper',
            'scientific_name' => '  epinephelus   fasciatus  ', // lowercase and extra spaces
            'confidence'      => 0.90,
            'visual_features' => ['Black tips on dorsal fin'],
            'reasoning'       => 'Black tipped dorsal spines.',
        ];

        $openAiData = [
            'kiribati_name'   => 'Te Kuau',
            'common_name'     => 'Blacktip Grouper',
            'scientific_name' => 'Epinephelus fasciatus', // standard capitalized
            'confidence'      => 0.87,
            'visible_traits'  => ['Dorsal fin black margins'],
            'reasoning'       => 'Color bands and fin tips confirm species.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'medium',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/grouper.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'agreement');
        $response->assertJsonPath('comparison.scientific_name_match', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 9: Provider confidence values remain separate (never averaged)
    // ─────────────────────────────────────────────────────────────────────────
    public function test_provider_confidences_remain_separate_and_not_averaged(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museConfidence = 0.93;
        $openAiConfidence = 0.77;

        $museData = [
            'kiribati_name'   => 'Te Atun',
            'english_name'    => 'Skipjack Tuna',
            'scientific_name' => 'Katsuwonus pelamis',
            'confidence'      => $museConfidence,
            'visual_features' => ['Striped belly'],
            'reasoning'       => 'Longitudinal stripes on belly.',
        ];

        $openAiData = [
            'kiribati_name'   => 'Te Atun',
            'common_name'     => 'Skipjack Tuna',
            'scientific_name' => 'Katsuwonus pelamis',
            'confidence'      => $openAiConfidence,
            'visible_traits'  => ['Dark lateral stripes'],
            'reasoning'       => 'Pelagic streamlined body with belly stripes.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'low',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/skipjack.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame($museConfidence, $response->json('providers.muse_glimmer.confidence'));
        $this->assertSame($openAiConfidence, $response->json('providers.openai.confidence'));

        // Assert no average confidence exists
        $average = round(($museConfidence + $openAiConfidence) / 2, 2);
        $this->assertNotSame($average, $response->json('providers.muse_glimmer.confidence'));
        $this->assertNotSame($average, $response->json('providers.openai.confidence'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 10: Human verification remains required in all cases
    // ─────────────────────────────────────────────────────────────────────────
    public function test_human_confirmation_always_required_regardless_of_agreement(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Bwere',
            'english_name'    => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence'      => 0.99, // very high confidence
            'visual_features' => ['Paddle tail'],
            'reasoning'       => 'Clear photograph.',
        ];

        $openAiData = [
            'kiribati_name'   => 'Te Bwere',
            'common_name'     => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence'      => 0.99,
            'visible_traits'  => ['Paddle tail'],
            'reasoning'       => 'Clear specimen.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'high',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/perfect_photo.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison.human_confirmation_required', true);
        $response->assertJsonPath('identification.requires_human_confirmation', true);
        $this->assertStringContainsString('advisory', (string) $response->json('disclaimer'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 11: API keys are never returned or logged
    // ─────────────────────────────────────────────────────────────────────────
    public function test_api_keys_are_never_leaked_in_responses(): void
    {
        $secretMetaToken = 'META_SUPER_SECRET_TOKEN_9999';
        $secretOpenAiToken = 'OPENAI_SUPER_SECRET_TOKEN_8888';

        Config::set('services.meta_ai.api_key', $secretMetaToken);
        Config::set('services.openai.api_key', $secretOpenAiToken);

        $museData = [
            'kiribati_name'   => 'Te Okaoka',
            'english_name'    => 'Titan Triggerfish',
            'scientific_name' => 'Balistoides viridescens',
            'confidence'      => 0.92,
            'visual_features' => ['Rhomboid shape'],
            'reasoning'       => 'Triggerfish morphology.',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response(['error' => ['message' => 'Unauthorized error simulation']], 401),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/test_secrets.jpg',
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $body = $response->getContent();

        $this->assertStringNotContainsString($secretMetaToken, $body);
        $this->assertStringNotContainsString($secretOpenAiToken, $body);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 12: Database persistence in fish_identification_providers table
    // ─────────────────────────────────────────────────────────────────────────
    public function test_provider_evaluations_persist_to_database_correctly(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $catch = CatchRecord::first();
        $this->assertNotNull($catch);

        $museData = [
            'kiribati_name'   => 'Te Bwere',
            'english_name'    => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence'      => 0.93,
            'visual_features' => ['Paddle tail', 'Red body'],
            'reasoning'       => 'Clear Lutjanus gibbus specimen.',
        ];

        $openAiData = [
            'kiribati_name'   => 'Te Bwere',
            'common_name'     => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence'      => 0.89,
            'visible_traits'  => ['Reddish scales', 'Paddle tail'],
            'reasoning'       => 'Diagnostic paddle tail observed.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'high',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/persist_test.jpg',
            'catch_id'  => $catch->id,
            'compare'   => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['persisted_ids' => ['muse_glimmer', 'openai']]);

        // Verify rows in fish_identification_providers
        $this->assertDatabaseHas('fish_identification_providers', [
            'catch_id'         => $catch->id,
            'provider_name'    => 'muse_glimmer',
            'scientific_name'  => 'Lutjanus gibbus',
            'error_status'     => 'success',
        ]);

        $this->assertDatabaseHas('fish_identification_providers', [
            'catch_id'         => $catch->id,
            'provider_name'    => 'openai',
            'scientific_name'  => 'Lutjanus gibbus',
            'error_status'     => 'success',
        ]);
    }

    public function test_identify_fish_with_openai_provider_selection(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $openAiData = [
            'kiribati_name'   => 'Te Ingimea',
            'common_name'     => 'Yellowfin Tuna',
            'scientific_name' => 'Thunnus albacares',
            'confidence'      => 0.94,
            'visible_traits'  => ['Yellow dorsal finlets', 'Streamlined torpedo body'],
            'reasoning'       => 'Sickle dorsal fins match Thunnus albacares.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'low',
        ];

        Http::fake([
            'api.openai.com/*' => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/yellowfin.jpg',
            'provider'  => 'openai',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'            => 'success',
            'selected_provider' => 'openai',
            'identification'    => [
                'scientific_name' => 'Thunnus albacares',
                'kiribati_name'   => 'Te Ingimea',
                'english_name'    => 'Yellowfin Tuna',
                'model'           => 'OpenAI Vision (gpt-4o)',
            ],
        ]);
    }

    public function test_identify_fish_with_meta_provider_selection(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Bwere',
            'english_name'    => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'confidence'      => 0.91,
            'visual_features' => ['Paddletail', 'Red scales'],
            'reasoning'       => 'Deep red coloration and rounded tail.',
            'ciguatera_risk'  => 'high',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/paddletail.jpg',
            'provider'  => 'meta',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'            => 'success',
            'selected_provider' => 'muse_glimmer',
            'identification'    => [
                'scientific_name' => 'Lutjanus gibbus',
                'kiribati_name'   => 'Te Bwere',
                'english_name'    => 'Paddletail Snapper',
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 15: Offline fallback when providers unreachable returns honest unavailability
    // ─────────────────────────────────────────────────────────────────────────
    public function test_offline_fallback_when_providers_unreachable_returns_catalog_grounded_comparison(): void
    {
        // Unconfigured or unreachable external hosts
        Config::set('services.meta_ai.api_key', null);
        Config::set('services.openai.api_key', null);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        // Specimen photo with hint for Te Bwere
        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/specimens/te_bwere.jpg',
            'hint'      => 'Te Bwere',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'both_failed');
        $response->assertJsonPath('status', 'unavailable');
        $response->assertJsonPath('providers.muse_glimmer.status', 'unavailable');
        $this->assertNull($response->json('providers.muse_glimmer.confidence'));
        $this->assertNull($response->json('providers.muse_glimmer.suggested_species'));

        $response->assertJsonPath('providers.openai.status', 'unavailable');
        $this->assertNull($response->json('providers.openai.confidence'));
        $this->assertNull($response->json('providers.openai.suggested_species'));

        // Offline catalogue reference is provided honestly for human inspection
        $response->assertJsonPath('offline_catalogue.status', 'fallback');
        $response->assertJsonPath('offline_catalogue.suggested_species.kiribati_name', 'Te Bwere');
        $this->assertNull($response->json('offline_catalogue.confidence'));
        $response->assertJsonPath('offline_catalogue.confidence_text', 'Not available');

        $response->assertJsonPath('comparison.human_confirmation_required', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 16: Offline single provider selection returns unavailable when unconfigured
    // ─────────────────────────────────────────────────────────────────────────
    public function test_offline_single_provider_selection_falls_back_to_catalog(): void
    {
        Config::set('services.openai.api_key', null);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/specimens/te_okaoka.jpg',
            'hint'      => 'Te Okaoka',
            'provider'  => 'openai',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'unavailable');
        $response->assertJsonPath('selected_provider', 'openai');
        $this->assertNull($response->json('identification.confidence'));
        $response->assertJsonPath('identification.confidence_percentage', 'Not available');
        $this->assertNull($response->json('identification.kiribati_name'));
        $this->assertNull($response->json('identification.scientific_name'));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 10 BONEFISH VS TE KUAU REGRESSION TESTS (P0-20 Integrity Suite)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Regression Test 1: Bonefish image is NOT classified as Te Kuau by fallback.
     */
    public function test_bonefish_image_not_classified_as_te_kuau_by_fallback(): void
    {
        Config::set('services.meta_ai.api_key', null);
        Config::set('services.openai.api_key', null);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        // Image path whose CRC32 previously selected Te Kuau at index 3
        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/bonefish_specimen_001.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'both_failed');
        $this->assertNotEquals('Te Kuau', $response->json('identification.kiribati_name'));
        $this->assertNotEquals('Epinephelus fasciatus', $response->json('identification.scientific_name'));
        $this->assertNull($response->json('providers.muse_glimmer.suggested_species'));
        $this->assertNull($response->json('providers.openai.suggested_species'));
    }

    /**
     * Regression Test 2: Te Kuau image is NOT classified as bonefish by fallback.
     */
    public function test_te_kuau_image_not_classified_as_bonefish_by_fallback(): void
    {
        Config::set('services.meta_ai.api_key', null);
        Config::set('services.openai.api_key', null);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/te_kuau_grouper.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'both_failed');
        $this->assertNotEquals('Albula glossodonta', $response->json('identification.scientific_name'));
        $this->assertNull($response->json('identification.scientific_name'));
    }

    /**
     * Regression Test 3: If both external APIs are unavailable, neither provider claims successful identification.
     */
    public function test_unreachable_external_apis_neither_provider_claims_successful_identification(): void
    {
        Config::set('services.meta_ai.api_key', 'test_key');
        Config::set('services.openai.api_key', 'test_key');

        Http::fake([
            'https://api.meta.ai/*' => Http::response(['error' => 'Service Unavailable'], 503),
            'api.openai.com/*'      => Http::response(['error' => 'Connection Refused'], 502),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/test_fail.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'both_failed');
        $this->assertNotEquals('success', $response->json('providers.muse_glimmer.status'));
        $this->assertNotEquals('success', $response->json('providers.openai.status'));
        $this->assertNull($response->json('providers.muse_glimmer.suggested_species'));
        $this->assertNull($response->json('providers.openai.suggested_species'));
    }

    /**
     * Regression Test 4: If both providers fail, confidence is strictly null.
     */
    public function test_when_both_providers_fail_confidence_is_strictly_null(): void
    {
        Config::set('services.meta_ai.api_key', null);
        Config::set('services.openai.api_key', null);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/unreachable.jpg',
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('providers.muse_glimmer.confidence'));
        $this->assertNull($response->json('providers.openai.confidence'));
        $this->assertNull($response->json('identification.confidence'));
        $response->assertJsonPath('identification.confidence_percentage', 'Not available');
    }

    /**
     * Regression Test 5: If Muse Glimmer succeeds and OpenAI fails, preserve Muse Glimmer only.
     */
    public function test_when_muse_glimmer_succeeds_and_openai_fails_preserve_muse_glimmer_only(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Ikari',
            'english_name'    => 'Bonefish',
            'scientific_name' => 'Albula glossodonta',
            'confidence'      => 0.93,
            'visual_features' => ['Silver slender body', 'Inferior mouth'],
            'reasoning'       => 'Morphology matches Albula glossodonta.',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response(['error' => 'API Error'], 500),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/bonefish.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'single_provider_result');
        $response->assertJsonPath('providers.muse_glimmer.status', 'success');
        $response->assertJsonPath('providers.muse_glimmer.scientific_name', 'Albula glossodonta');
        $this->assertEquals(0.93, $response->json('providers.muse_glimmer.confidence'));

        $this->assertNotEquals('success', $response->json('providers.openai.status'));
        $this->assertNull($response->json('providers.openai.suggested_species'));
        $this->assertNull($response->json('providers.openai.confidence'));

        $response->assertJsonPath('identification.scientific_name', 'Albula glossodonta');
        $this->assertEquals(0.93, $response->json('identification.confidence'));
    }

    /**
     * Regression Test 6: If OpenAI succeeds and Muse Glimmer fails, preserve OpenAI only.
     */
    public function test_when_openai_succeeds_and_muse_glimmer_fails_preserve_openai_only(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $openAiData = [
            'kiribati_name'   => 'Te Ikari',
            'common_name'     => 'Bonefish',
            'scientific_name' => 'Albula glossodonta',
            'confidence'      => 0.89,
            'visible_traits'  => ['Silvery cylindrical body', 'Conical snout'],
            'reasoning'       => 'Diagnostic features of Albula glossodonta observed.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'low',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response(['error' => 'API Timeout'], 504),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/bonefish.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'single_provider_result');
        $this->assertNotEquals('success', $response->json('providers.muse_glimmer.status'));
        $this->assertNull($response->json('providers.muse_glimmer.suggested_species'));

        $response->assertJsonPath('providers.openai.status', 'success');
        $response->assertJsonPath('providers.openai.scientific_name', 'Albula glossodonta');
        $this->assertEquals(0.89, $response->json('providers.openai.confidence'));

        $response->assertJsonPath('identification.scientific_name', 'Albula glossodonta');
        $this->assertEquals(0.89, $response->json('identification.confidence'));
    }

    /**
     * Regression Test 7: If both succeed with different species, mark disagreement.
     */
    public function test_when_both_succeed_with_different_species_mark_disagreement(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Ikari',
            'english_name'    => 'Bonefish',
            'scientific_name' => 'Albula glossodonta',
            'confidence'      => 0.91,
            'visual_features' => ['Silver body'],
            'reasoning'       => 'Bonefish features.',
        ];

        $openAiData = [
            'kiribati_name'   => 'Te Kuau',
            'common_name'     => 'Blacktip Grouper',
            'scientific_name' => 'Epinephelus fasciatus',
            'confidence'      => 0.87,
            'visible_traits'  => ['Red banding'],
            'reasoning'       => 'Grouper coloration.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'medium',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/ambiguous.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'disagreement');
        $response->assertJsonPath('comparison.scientific_name_match', false);
        $response->assertJsonPath('comparison.human_confirmation_required', true);
    }

    /**
     * Regression Test 8: If both succeed with same species, mark agreement with human_confirmation_required: true.
     */
    public function test_when_both_succeed_with_same_species_mark_agreement_with_human_confirmation_required(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $museData = [
            'kiribati_name'   => 'Te Ikari',
            'english_name'    => 'Bonefish',
            'scientific_name' => 'Albula glossodonta',
            'confidence'      => 0.94,
            'visual_features' => ['Silver body', 'Underslung jaw'],
            'reasoning'       => 'Albula glossodonta.',
        ];

        $openAiData = [
            'kiribati_name'   => 'Te Ikari',
            'common_name'     => 'Bonefish',
            'scientific_name' => 'Albula glossodonta',
            'confidence'      => 0.90,
            'visible_traits'  => ['Silvery cylindrical profile'],
            'reasoning'       => 'Albula glossodonta identified.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'low',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response($this->mockMuseResponse($museData), 200),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/bonefish.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'agreement');
        $response->assertJsonPath('comparison.scientific_name_match', true);
        $response->assertJsonPath('comparison.human_confirmation_required', true);
    }

    /**
     * Regression Test 9: If species does not exist in local catalogue, preserve as unverified suggestion.
     */
    public function test_species_not_in_local_catalogue_preserved_as_unverified_suggestion(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        $openAiData = [
            'kiribati_name'   => 'Te Unknown',
            'common_name'     => 'Great Barracuda',
            'scientific_name' => 'Sphyraena barracuda',
            'confidence'      => 0.88,
            'visible_traits'  => ['Fang-like teeth', 'Elongated body'],
            'reasoning'       => 'Classic barracuda morphology.',
            'alternatives'    => [],
            'ciguatera_risk'  => 'high',
        ];

        Http::fake([
            'https://api.meta.ai/*' => Http::response(['error' => 'No match'], 404),
            'api.openai.com/*'      => Http::response($this->mockOpenAiResponse($openAiData), 200),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/barracuda.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('providers.openai.status', 'success');
        $response->assertJsonPath('providers.openai.scientific_name', 'Sphyraena barracuda');
        $this->assertEquals('Sphyraena barracuda', $response->json('identification.scientific_name'));
        $this->assertNotEquals('Epinephelus fasciatus', $response->json('identification.scientific_name'));
    }

    /**
     * Regression Test 10: Invalid/unclear photos do not receive forced species identification.
     */
    public function test_invalid_or_unclear_photos_do_not_receive_forced_species_identification(): void
    {
        Config::set('services.meta_ai.api_key', 'test_meta_key');
        Config::set('services.openai.api_key', 'test_openai_key');

        Http::fake([
            'https://api.meta.ai/*' => Http::response(['error' => 'Image too blurry'], 422),
            'api.openai.com/*'      => Http::response(['error' => ['message' => 'Unrecognizable image']], 422),
        ]);

        $admin = User::where('role', 'admin')->first();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/compare-providers', [
            'image_url' => 'https://example.com/blurry_sea.jpg',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('comparison_status', 'both_failed');
        $this->assertNull($response->json('providers.muse_glimmer.suggested_species'));
        $this->assertNull($response->json('providers.openai.suggested_species'));
        $this->assertNull($response->json('identification.scientific_name'));
        $this->assertNull($response->json('identification.kiribati_name'));
        $this->assertNull($response->json('identification.confidence'));
        $response->assertJsonPath('identification.confidence_percentage', 'Not available');
        $response->assertJsonPath('identification.requires_human_confirmation', true);
    }

    /**
     * P0-25: SAM success must never masquerade as Muse species identification.
     * SAM is segmentation evidence only: no species, no invented confidence.
     */
    public function test_sam_success_does_not_masquerade_as_muse_species_identification(): void
    {
        $service = app(FishIdentificationComparisonService::class);

        $normalized = $service->normalizeMuseResponse(
            ['status' => 'model_unavailable', 'model' => 'muse-spark-1.3', 'identification' => null, 'error' => 'Model not found', 'latency_ms' => 12],
            null,
            12,
            [
                'status' => 'ok',
                'fish_detected' => true,
                'object_count' => 2,
                'best_box' => ['x1' => 1, 'y1' => 2, 'x2' => 30, 'y2' => 40, 'has_mask' => true],
                'latency_ms' => 99,
                'raw_output' => 'sam-raw',
            ]
        );

        $this->assertSame('model_unavailable', $normalized['status']);
        $this->assertNull($normalized['suggested_species']);
        $this->assertNull($normalized['confidence']);
        $this->assertNull($normalized['scientific_name']);
    }
}
