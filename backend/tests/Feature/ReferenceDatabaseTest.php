<?php

namespace Tests\Feature;

use App\Models\Species;
use App\Models\User;
use App\Services\MetaAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReferenceDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['services.meta_ai.api_key' => null]);
    }

    public function test_all_35_reference_entries_imported_with_verbatim_scientific_names(): void
    {
        $this->assertSame(42, Species::count());

        // Verbatim, including misspellings that must not be silently fixed.
        $this->assertDatabaseHas('species', [
            'kiribati_name' => 'Te Bun',
            'scientific_name' => 'Anadara uropigmelana',
            'scientific_name_as_provided' => 'Anadara uropigmelana',
            'verification_status' => 'needs_review',
        ]);
        $this->assertDatabaseHas('species', [
            'kiribati_name' => 'Te Nikatona',
            'scientific_name_as_provided' => 'Quidnipagus palatam',
            'verification_status' => 'needs_review',
        ]);
        $this->assertDatabaseHas('species', [
            'kiribati_name' => 'Te Iaia',
            'scientific_name_as_provided' => '-',
            'verification_status' => 'needs_review',
        ]);
        $this->assertDatabaseHas('species', [
            'kiribati_name' => 'Te Bwere/Katati',
            'scientific_name_as_provided' => 'Pinnidae',
            'verification_status' => 'needs_review',
        ]);
    }

    public function test_record_types_distinguish_species_groups_and_non_fish(): void
    {
        $this->assertSame('species', Species::where('kiribati_name', 'Te Baiura')->value('record_type'));
        $this->assertSame('taxonomic_group', Species::where('kiribati_name', 'Te Aua')->value('record_type'));
        $this->assertSame('taxonomic_group', Species::where('kiribati_name', 'Te Ika Uraura')->value('record_type'));
        $this->assertSame('non_fish_organism', Species::where('kiribati_name', 'Te Kiika')->value('record_type'));
        $this->assertSame('non_fish_organism', Species::where('kiribati_name', 'Te Bwaitari')->value('record_type'));
        $this->assertSame('non_fish_organism', Species::where('kiribati_name', 'Te Koikoi')->value('record_type'));

        $this->assertSame(12, Species::where('record_type', 'non_fish_organism')->count());
        $this->assertSame(9, Species::where('record_type', 'taxonomic_group')->count());
    }

    public function test_curated_species_stay_verified_and_untouched(): void
    {
        $ikari = Species::where('kiribati_name', 'Te Ikari')->first();
        $this->assertSame('verified', $ikari->verification_status);
        $this->assertSame('Albula glossodonta', $ikari->scientific_name);
        $this->assertSame('Albula glossodonta', $ikari->scientific_name_as_provided);
        $this->assertSame('low', $ikari->ciguatera_risk);
        $this->assertNotEmpty($ikari->photo_url);

        $this->assertSame(9, Species::where('verification_status', 'verified')->count());
        $this->assertSame(33, Species::where('verification_status', 'needs_review')->count());
    }

    public function test_reference_seeder_is_idempotent_and_backfills(): void
    {
        $this->seed(\Database\Seeders\KiribatiMarineSpeciesSeeder::class);
        $this->seed(\Database\Seeders\KiribatiMarineSpeciesSeeder::class);

        $this->assertSame(42, Species::count());
        $lower = Species::pluck('kiribati_name')->map(fn ($n) => strtolower($n));
        $this->assertSame($lower->count(), $lower->unique()->count());
    }

    public function test_species_api_filters_by_verification_and_record_type(): void
    {
        $admin = User::where('role', 'admin')->first();

        $needsReview = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/species?verification_status=needs_review');
        $needsReview->assertStatus(200);
        $this->assertSame(33, $needsReview->json('count'));
        foreach ($needsReview->json('data') as $row) {
            $this->assertSame('needs_review', $row['verification_status']);
            $this->assertFalse($row['is_verified_record']);
            $this->assertTrue($row['requires_human_review']);
            $this->assertArrayHasKey('scientific_name_as_provided', $row);
            $this->assertArrayHasKey('record_type', $row);
        }

        $nonFish = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/species?record_type=non_fish_organism');
        $nonFish->assertStatus(200);
        $this->assertSame(12, $nonFish->json('count'));

        $verified = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/species?verification_status=verified');
        $verified->assertStatus(200);
        $this->assertSame(9, $verified->json('count'));
    }

    public function test_identify_returns_reference_candidates_labelled_by_verification(): void
    {
        Http::fake();
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://example.com/fish.jpg',
            'hint' => 'Te Baiura',
        ]);

        $response->assertStatus(200);
        $id = $response->json('identification');
        // Needs-review import must not be presented as verified.
        $this->assertSame('needs_review', $id['verification_status']);
        $this->assertFalse($id['is_verified_record']);
        $this->assertTrue($id['requires_human_confirmation']);
        $this->assertSame('reference_database', $id['source']);
        $this->assertNotEmpty($id['reference_candidates']);
        $candidate = collect($id['reference_candidates'])->firstWhere('kiribati_name', 'Te Baiura');
        $this->assertNotNull($candidate);
        $this->assertSame('needs_review', $candidate['verification_status']);
        $this->assertSame('Thunnus albacares', $candidate['scientific_name_as_provided']);
        $this->assertArrayNotHasKey('confidence', $candidate);
        // SAM stays separate from species naming.
        $this->assertArrayHasKey('segmentation', $id);
        $this->assertStringContainsString('cannot detect', $response->json('disclaimer'));
        Http::assertNothingSent();
    }

    public function test_identify_verified_candidate_is_labelled_verified(): void
    {
        Http::fake();
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/ai/identify-fish', [
            'image_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
            'hint' => 'Te Bwere',
        ]);

        $response->assertStatus(200);
        $this->assertSame('verified', $response->json('identification.verification_status'));
        $this->assertTrue($response->json('identification.is_verified_record'));
        Http::assertNothingSent();
    }

    public function test_image_modality_probe_makes_no_call_when_unconfigured(): void
    {
        Http::fake();
        $service = new MetaAiService('https://api.meta.ai/v1/responses', 'muse-wonder-1.0', '', 5);

        $result = $service->investigateImageModality('https://example.com/fish.jpg');

        $this->assertFalse($result['tested']);
        $this->assertNull($result['supports_image']);
        $this->assertTrue($result['known_text_only']);
        $this->assertSame('sam-3.1', $result['sam_image_model']);
        Http::assertNothingSent();
    }

    public function test_meta_health_reports_image_modality_without_probing(): void
    {
        Http::fake();
        config(['services.meta_ai.api_key' => null]);
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/ai/meta-health');

        $response->assertStatus(200);
        $response->assertJsonPath('image_modality.muse_wonder_known_text_only', true);
        $response->assertJsonPath('image_modality.muse_wonder_tested', false);
        $response->assertJsonPath('image_modality.sam_supports_image', true);
        Http::assertNothingSent();
    }
}
