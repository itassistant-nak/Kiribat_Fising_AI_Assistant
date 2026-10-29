<?php

namespace Tests\Feature;

use App\Models\CatchRecord;
use App\Models\User;
use App\Services\SamSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SamSegmentationTest extends TestCase
{
    use RefreshDatabase;

    protected User $fisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fisher = User::create([
            'name' => 'Teititeki Tabua',
            'email' => 'teititeki@fisher.ki',
            'password' => bcrypt('FisherPass2026!'),
            'role' => 'fisher',
        ]);
    }

    /** SAM 3 grammar line: two objects; first carries a (codec-opaque) mask payload. */
    protected function grammarLine(): string
    {
        return '<0f>0<|box;x1=10;y1=10;x2=13;y2=11;w=4;h=1|><|mask;x=0;y=0;data=120|>,1<|box;x1=0;y1=0;x2=2;y2=1;w=4;h=1|>';
    }

    protected function sseBody(string $grammar): string
    {
        $half = (int) (strlen($grammar) / 2);
        $events = [
            ['type' => 'response.output_text.delta', 'delta' => substr($grammar, 0, $half)],
            ['type' => 'response.output_text.delta', 'delta' => substr($grammar, $half)],
            ['type' => 'response.content_part.done', 'part' => ['type' => 'output_text', 'text' => $grammar]],
            ['type' => 'response.completed'],
        ];
        $body = '';
        foreach ($events as $event) {
            $body .= 'data: ' . json_encode($event) . "\n\n";
        }
        return $body . "data: [DONE]\n\n";
    }

    protected function fakeSamOk(): void
    {
        config(['services.meta_ai.api_key' => 'test-sam-key']);
        Http::fake([
            'https://api.meta.ai/*' => function ($request) {
                $payload = $request->data();
                if (($payload['model'] ?? null) === 'sam-3.1') {
                    return Http::response($this->sseBody($this->grammarLine()), 200, [
                        'Content-Type' => 'text/event-stream',
                    ]);
                }
                // Meta text reasoning probe
                return Http::response([
                    'id' => 'resp_fake',
                    'status' => 'completed',
                    'output' => [['content' => [['type' => 'output_text', 'text' => 'Advisory note.']]]],
                ], 200);
            },
        ]);
    }

    public function test_unconfigured_reports_honestly_without_http(): void
    {
        Http::fake();
        // '' (not null) so the constructor never falls back to a real .env key
        $service = new SamSegmentationService('https://api.meta.ai/v1', 'sam-3.1', '', 5);

        $result = $service->segment('data:image/jpeg;base64,AAAA');

        $this->assertSame('unconfigured', $result['status']);
        $this->assertFalse($result['fish_detected']);
        Http::assertNothingSent();
    }

    public function test_segment_decodes_sse_grammar_boxes_presence_and_raw(): void
    {
        $this->fakeSamOk();
        $service = new SamSegmentationService('https://api.meta.ai/v1', 'sam-3.1', 'test-sam-key', 5);

        $result = $service->segment('data:image/jpeg;base64,AAAA');

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['fish_detected']);
        $this->assertSame(2, $result['object_count']);
        $this->assertSame('0', $result['objects'][0]['id']);
        $this->assertSame(13, $result['objects'][0]['x2']);
        $this->assertTrue($result['objects'][0]['has_mask']);
        // Mask payloads are arithmetic-coded: coverage honestly unknown
        $this->assertNull($result['objects'][0]['mask_coverage']);
        $this->assertNull($result['mask_coverage_max']);
        $this->assertFalse($result['objects'][1]['has_mask']);
        $this->assertSame('0', $result['best_box']['id']);
        $this->assertSame($this->grammarLine(), $result['raw_output']);
    }

    public function test_segment_handles_non_stream_json_body(): void
    {
        config(['services.meta_ai.api_key' => 'test-sam-key']);
        Http::fake([
            'https://api.meta.ai/*' => Http::response([
                'output' => [[
                    'content' => [['type' => 'output_text', 'text' => $this->grammarLine()]],
                ]],
            ], 200),
        ]);
        $service = new SamSegmentationService('https://api.meta.ai/v1', 'sam-3.1', 'test-sam-key', 5);

        $result = $service->segment('data:image/jpeg;base64,AAAA');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['object_count']);
    }

    public function test_segment_maps_unauthorized_and_model_unavailable(): void
    {
        config(['services.meta_ai.api_key' => 'test-sam-key']);
        Http::fake([
            'https://api.meta.ai/*' => Http::sequence()
                ->push(['error' => ['message' => 'Invalid API key']], 401)
                ->push(['error' => ['message' => 'model_not_found: sam-3.1']], 404),
        ]);
        $service = new SamSegmentationService('https://api.meta.ai/v1', 'sam-3.1', 'test-sam-key', 5);

        $this->assertSame('unauthorized', $service->segment('data:image/jpeg;base64,AAAA')['status']);
        $this->assertSame('model_unavailable', $service->segment('data:image/jpeg;base64,AAAA')['status']);
    }

    public function test_identify_returns_segmentation_evidence_and_raw_verbatim(): void
    {
        $this->fakeSamOk();
        Sanctum::actingAs($this->fisher);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
            'hint' => 'Te Bwere',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('identification.kiribati_name', 'Te Bwere')
            ->assertJsonPath('identification.segmentation.provider', 'Meta SAM 3.1 (Meta Model API)')
            ->assertJsonPath('identification.segmentation.status', 'ok')
            ->assertJsonPath('identification.segmentation.fish_detected', true)
            ->assertJsonPath('identification.segmentation.object_count', 2)
            ->assertJsonPath('identification.segmentation_warning', null)
            ->assertJsonPath('segmentation_raw', $this->grammarLine());
    }

    public function test_identify_warns_when_sam_finds_no_fish(): void
    {
        config(['services.meta_ai.api_key' => 'test-sam-key']);
        Http::fake([
            'https://api.meta.ai/*' => function ($request) {
                $payload = $request->data();
                if (($payload['model'] ?? null) === 'sam-3.1') {
                    return Http::response($this->sseBody(''), 200, ['Content-Type' => 'text/event-stream']);
                }
                return Http::response([
                    'id' => 'resp_fake',
                    'status' => 'completed',
                    'output' => [['content' => [['type' => 'output_text', 'text' => 'Advisory note.']]]],
                ], 200);
            },
        ]);
        Sanctum::actingAs($this->fisher);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
            'hint' => 'Te Bwere',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('identification.segmentation.fish_detected', false)
            ->assertJsonPath('identification.segmentation.object_count', 0);

        $this->assertStringContainsString('no fish', (string) $response->json('identification.segmentation_warning'));
    }

    public function test_identify_unconfigured_keeps_knowledge_base_answer(): void
    {
        // Force-unconfigured: the developer .env may hold a real key now
        config(['services.meta_ai.api_key' => null]);
        Http::fake();
        Sanctum::actingAs($this->fisher);

        $response = $this->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
            'hint' => 'Te Bwere',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('identification.kiribati_name', 'Te Bwere')
            ->assertJsonPath('identification.segmentation.status', 'unconfigured')
            ->assertJsonPath('segmentation_raw', null);
    }

    public function test_catch_store_persists_ai_metadata_verbatim(): void
    {
        Sanctum::actingAs($this->fisher);

        $response = $this->postJson('/api/catches', [
            'kiribati_name' => 'Te Bwere',
            'english_name' => 'Paddletail Snapper',
            'ai_species_suggestion' => 'Te Bwere (Paddletail Snapper)',
            'ai_confidence' => 0.94,
            'ai_metadata' => [
                'provider' => 'Meta SAM 3.1 (Meta Model API)',
                'model' => 'sam-3.1',
                'raw_output' => $this->grammarLine(),
            ],
        ]);

        $response->assertStatus(201);
        $catch = CatchRecord::latest('id')->first();
        $this->assertSame('Meta SAM 3.1 (Meta Model API)', $catch->ai_metadata['provider']);
        $this->assertSame($this->grammarLine(), $catch->ai_metadata['raw_output']);
    }
}
