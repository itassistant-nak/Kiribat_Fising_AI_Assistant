<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MetaAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0-09 — Muse Wonder 1.0 fisheries assistant (deepen + test).
 *
 * Covers MetaAiService::queryFisheriesAssistant() and
 * MetaAiService::investigateImageModality(), plus the combined
 * SAM + KB + Muse path in POST /api/ai/identify-fish.
 *
 * Every Meta call is mocked (Http::fake) or forced unconfigured;
 * no test may bill the real API (ADR-016 test-isolation rule).
 */
class FisheriesAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Minimal SAM 3 grammar line: one fish object with a box. */
    protected function samGrammar(): string
    {
        return '<0f>0<|box;x1=10;y1=10;x2=13;y2=11;w=4;h=1|>';
    }

    protected function completedMetaResponse(string $text): array
    {
        return [
            'id' => 'resp_p0_09',
            'status' => 'completed',
            'model' => 'muse-wonder-1.0',
            'output' => [
                [
                    'content' => [
                        ['type' => 'output_text', 'text' => $text],
                    ],
                ],
            ],
        ];
    }

    public function test_fisheries_assistant_unconfigured_sends_no_request(): void
    {
        Http::fake();

        // '' (not null) so the constructor never falls back to a real .env key.
        $service = new MetaAiService('https://api.meta.ai/v1/responses', 'muse-wonder-1.0', '', 5);

        $result = $service->queryFisheriesAssistant(
            'Te Bwere',
            'Paddletail Snapper',
            'Lutjanus gibbus',
            ['status' => 'ok', 'object_count' => 1, 'best_box' => ['id' => '0']],
            'seen near the passage'
        );

        $this->assertSame('unconfigured', $result['status']);
        $this->assertNull($result['advisory']);
        $this->assertSame('muse-wonder-1.0', $result['model']);
        Http::assertNothingSent();
    }

    public function test_fisheries_assistant_returns_advisory_grounded_in_kb_sam_and_hint(): void
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response(
                $this->completedMetaResponse('Reef-associated snapper of outer Tarawa passages.'),
                200
            ),
        ]);

        $service = new MetaAiService('https://api.meta.ai/v1/responses', 'muse-wonder-1.0', 'test_p0_09_key', 5);

        $result = $service->queryFisheriesAssistant(
            'Te Bwere',
            'Paddletail Snapper',
            'Lutjanus gibbus',
            ['status' => 'ok', 'object_count' => 2, 'best_box' => ['id' => '0']],
            'caught at the north passage'
        );

        $this->assertSame('completed', $result['status']);
        $this->assertSame('Reef-associated snapper of outer Tarawa passages.', $result['advisory']);
        $this->assertSame('muse-wonder-1.0', $result['model']);
        $this->assertNull($result['error']);

        Http::assertSent(function ($request) {
            $prompt = (string) ($request['input'] ?? '');

            return $request->url() === 'https://api.meta.ai/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test_p0_09_key')
                && $request['model'] === 'muse-wonder-1.0'
                // Prompt is grounded in the KB candidate, SAM evidence and hint.
                && str_contains($prompt, 'Te Bwere')
                && str_contains($prompt, 'Paddletail Snapper')
                && str_contains($prompt, 'Lutjanus gibbus')
                && str_contains($prompt, "segmented 2 object(s) matching concept 'fish'")
                && str_contains($prompt, 'Largest bounding box confirmed present.')
                && str_contains($prompt, 'caught at the north passage')
                // Anti-invention guardrails must reach the model verbatim.
                && str_contains($prompt, 'Do NOT suggest alternative species names')
                && str_contains($prompt, 'Do NOT invent confidence scores')
                && str_contains($prompt, 'only laboratory analysis can do that');
        });
    }

    public function test_fisheries_assistant_reflects_sam_evidence_variants_in_prompt(): void
    {
        $prompts = [];
        Http::fake([
            'https://api.meta.ai/v1/responses' => function ($request) use (&$prompts) {
                $prompts[] = (string) ($request['input'] ?? '');

                return Http::response($this->completedMetaResponse('Advisory.'), 200);
            },
        ]);

        $service = new MetaAiService('https://api.meta.ai/v1/responses', 'muse-wonder-1.0', 'test_p0_09_key', 5);

        $service->queryFisheriesAssistant('Te Bwere', 'Paddletail Snapper', 'Lutjanus gibbus', []);
        $service->queryFisheriesAssistant('Te Bwere', 'Paddletail Snapper', 'Lutjanus gibbus', ['status' => 'unconfigured']);
        $service->queryFisheriesAssistant('Te Bwere', 'Paddletail Snapper', 'Lutjanus gibbus', ['status' => 'image_unavailable']);
        $service->queryFisheriesAssistant('Te Bwere', 'Paddletail Snapper', 'Lutjanus gibbus', ['status' => 'ok', 'object_count' => 0, 'best_box' => null]);
        $service->queryFisheriesAssistant('Te Bwere', 'Paddletail Snapper', 'Lutjanus gibbus', ['status' => 'unauthorized']);

        $this->assertCount(5, $prompts);
        $this->assertStringContainsString('SAM 3.1 segmentation: unconfigured or unavailable.', $prompts[0]);
        $this->assertStringContainsString('SAM 3.1 segmentation: not configured (API key absent).', $prompts[1]);
        $this->assertStringContainsString('SAM 3.1 segmentation: image bytes unavailable for analysis.', $prompts[2]);
        $this->assertStringContainsString("segmented 0 object(s) matching concept 'fish'.", $prompts[3]);
        $this->assertStringContainsString('No bounding box returned.', $prompts[3]);
        $this->assertStringContainsString('SAM 3.1 segmentation status: unauthorized.', $prompts[4]);
    }

    public function test_fisheries_assistant_api_failure_returns_null_advisory(): void
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'error' => ['message' => 'Rate limit exceeded on Muse Wonder model'],
            ], 429),
        ]);

        $service = new MetaAiService('https://api.meta.ai/v1/responses', 'muse-wonder-1.0', 'test_p0_09_key', 5);

        $result = $service->queryFisheriesAssistant('Te Bwere', 'Paddletail Snapper', 'Lutjanus gibbus');

        $this->assertSame('failed', $result['status']);
        $this->assertNull($result['advisory']);
        $this->assertStringContainsString('Rate limit exceeded', (string) $result['error']);
    }

    public function test_image_modality_probe_accepts_mocked_multimodal_response(): void
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response(
                $this->completedMetaResponse('A reef fish specimen on a measuring board.'),
                200
            ),
        ]);

        $service = new MetaAiService('https://api.meta.ai/v1/responses', 'muse-wonder-1.0', 'test_p0_09_key', 5);

        $result = $service->investigateImageModality('https://example.com/fish.jpg');

        $this->assertTrue($result['tested']);
        $this->assertTrue($result['supports_image']);
        $this->assertFalse($result['known_text_only']);
        $this->assertSame('A reef fish specimen on a measuring board.', $result['response_text']);
        $this->assertSame('sam-3.1', $result['sam_image_model']);

        Http::assertSent(function ($request) {
            $content = $request['input'][0]['content'] ?? [];

            return $request['model'] === 'muse-wonder-1.0'
                && ($content[1]['type'] ?? null) === 'image_url'
                && ($content[1]['image_url']['url'] ?? null) === 'https://example.com/fish.jpg';
        });
    }

    public function test_image_modality_probe_maps_rejection_to_text_only(): void
    {
        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response([
                'error' => ['message' => 'Image input is not supported by this model.'],
            ], 400),
        ]);

        $service = new MetaAiService('https://api.meta.ai/v1/responses', 'muse-wonder-1.0', 'test_p0_09_key', 5);

        $result = $service->investigateImageModality('https://example.com/fish.jpg');

        $this->assertTrue($result['tested']);
        $this->assertFalse($result['supports_image']);
        $this->assertTrue($result['known_text_only']);
        $this->assertSame(400, $result['status_code']);
        $this->assertSame('sam-3.1', $result['sam_image_model']);
    }

    public function test_identify_combines_kb_sam_and_muse_without_inventing_species(): void
    {
        config(['services.meta_ai.api_key' => 'test_p0_09_key']);

        $adversarialAdvisory = 'This is definitely Te Ingimea (Yellowfin Tuna), confidence 0.99, safe to eat.';
        $musePrompts = [];

        Http::fake([
            'https://api.meta.ai/*' => function ($request) use ($adversarialAdvisory, &$musePrompts) {
                $payload = $request->data();
                if (($payload['model'] ?? null) === 'sam-3.1') {
                    // Non-stream JSON fallback shape (proven by SamSegmentationTest).
                    return Http::response([
                        'output' => [[
                            'content' => [['type' => 'output_text', 'text' => $this->samGrammar()]],
                        ]],
                    ], 200);
                }
                $musePrompts[] = (string) ($payload['input'] ?? '');

                return Http::response($this->completedMetaResponse($adversarialAdvisory), 200);
            },
        ]);

        $fisher = User::where('role', 'fisher')->first();
        Sanctum::actingAs($fisher);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
            'hint' => 'Te Bwere',
        ]);

        $response->assertStatus(200);

        // Identity, risk and confidence stay with the KB even though the
        // advisory text names a different species with a fake confidence.
        $response->assertJsonPath('identification.kiribati_name', 'Te Bwere');
        $response->assertJsonPath('identification.english_name', 'Paddletail Snapper');
        $response->assertJsonPath('identification.scientific_name', 'Lutjanus gibbus');
        $response->assertJsonPath('identification.confidence', 0.94);
        $response->assertJsonPath('identification.requires_human_confirmation', true);

        // Muse output survives only as advisory notes; SAM stays separate evidence.
        $response->assertJsonPath('identification.meta_model.status', 'completed');
        $response->assertJsonPath('identification.meta_model.advisory_notes', $adversarialAdvisory);
        $response->assertJsonPath('identification.segmentation.status', 'ok');
        $response->assertJsonPath('identification.segmentation.fish_detected', true);
        $response->assertJsonPath('identification.segmentation.object_count', 1);
        $this->assertStringContainsString('cannot detect', (string) $response->json('disclaimer'));

        // The Muse prompt really received the SAM evidence (true combination).
        $this->assertNotEmpty($musePrompts);
        $this->assertStringContainsString("segmented 1 object(s) matching concept 'fish'", $musePrompts[0]);
        $this->assertStringContainsString('Do NOT suggest alternative species names', $musePrompts[0]);
    }

    public function test_identify_without_muse_key_keeps_kb_answer_and_null_advisory(): void
    {
        config(['services.meta_ai.api_key' => null]);
        Http::fake();

        $fisher = User::where('role', 'fisher')->first();
        Sanctum::actingAs($fisher);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
            'hint' => 'Te Bwere',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('identification.kiribati_name', 'Te Bwere')
            ->assertJsonPath('identification.meta_model.configured', false)
            ->assertJsonPath('identification.meta_model.status', 'unconfigured')
            ->assertJsonPath('identification.meta_model.advisory_notes', null)
            ->assertJsonPath('identification.segmentation.status', 'unconfigured');

        Http::assertNothingSent();
    }

    public function test_meta_health_with_key_sends_no_image_probe_and_leaks_no_key(): void
    {
        config(['services.meta_ai.api_key' => 'super_secret_p0_09_token']);

        Http::fake([
            'https://api.meta.ai/v1/responses' => Http::response(
                $this->completedMetaResponse('OK'),
                200
            ),
        ]);

        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/ai/meta-health');

        $response->assertStatus(200);
        $response->assertJsonPath('image_modality.muse_wonder_known_text_only', true);
        $response->assertJsonPath('image_modality.muse_wonder_tested', false);
        $response->assertJsonPath('image_modality.sam_supports_image', true);
        $this->assertStringNotContainsString('super_secret_p0_09_token', $response->getContent());

        // Exactly one call: the text connectivity probe. No image payload.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => !str_contains($request->body(), 'image_url'));
    }
}
