<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SpeciesCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // KB/catalog behavior only: never depend on (or bill) a real .env key
        config(['services.meta_ai.api_key' => null]);
    }

    public function test_admin_can_retrieve_full_species_catalog_with_ai_traits_and_counts()
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/species');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'kiribati_name',
                    'english_name',
                    'scientific_name',
                    'ciguatera_risk',
                    'photo_url',
                    'catches_count',
                    'advisory_zones_count',
                    'visual_features',
                    'ai_trained',
                ],
            ],
            'count',
            'knowledge_base_count',
        ]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(9, count($data));

        // Verify Te Bwere has AI visual features and photo
        $bwere = collect($data)->firstWhere('kiribati_name', 'Te Bwere');
        $this->assertNotNull($bwere);
        $this->assertEquals('Paddletail Snapper', $bwere['english_name']);
        $this->assertTrue($bwere['ai_trained']);
        $this->assertNotEmpty($bwere['visual_features']);
        $this->assertNotEmpty($bwere['photo_url']);
    }

    public function test_species_search_filters_correctly()
    {
        $admin = User::where('role', 'admin')->first();

        // Search by Kiribati name
        $res1 = $this->actingAs($admin, 'sanctum')->getJson('/api/species?search=Okaoka');
        $res1->assertStatus(200);
        $data1 = $res1->json('data');
        $this->assertCount(1, $data1);
        $this->assertEquals('Te Okaoka', $data1[0]['kiribati_name']);

        // Search by English common name (catalog expanded by P1-06:
        // several snapper entries now share this term)
        $res2 = $this->actingAs($admin, 'sanctum')->getJson('/api/species?search=Snapper');
        $res2->assertStatus(200);
        $data2 = $res2->json('data');
        $this->assertCount(5, $data2);
        $this->assertContains('Te Bwere', array_column($data2, 'kiribati_name'));
    }

    public function test_species_ciguatera_risk_filter_works()
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/species?ciguatera_risk=high');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        foreach ($data as $item) {
            $this->assertEquals('high', $item['ciguatera_risk']);
        }
    }

    public function test_reference_species_from_sql_file_are_in_catalog()
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/species');
        $response->assertStatus(200);

        // 9 curated + 33 new from the 35-row source (te ikari / te kuau
        // already exist under those Kiribati names and are preserved).
        $data = $response->json('data');
        $this->assertCount(42, $data);
        $this->assertEquals(42, $response->json('count'));

        $byName = collect($data)->keyBy('kiribati_name');

        // Distinct Kiribati name for a known scientific name: kept separate.
        $baiura = $byName->get('Te Baiura');
        $this->assertNotNull($baiura, 'Te Baiura missing from catalog');
        $this->assertEquals('Thunnus albacares', $baiura['scientific_name']);
        $this->assertEquals('unknown', $baiura['ciguatera_risk']);
        $this->assertFalse($baiura['ai_trained']);
        $this->assertStringContainsString('needs_review', $baiura['notes']);

        // Generic / non-fish source entries are imported verbatim too.
        $bwaitari = $byName->get('Te Bwaitari');
        $this->assertNotNull($bwaitari, 'Te Bwaitari missing from catalog');
        $this->assertEquals('Multiple species', $bwaitari['scientific_name']);
        $this->assertEquals('unknown', $bwaitari['ciguatera_risk']);

        // Slash name must not collide with the curated Te Bwere.
        $penshell = $byName->get('Te Bwere/Katati');
        $this->assertNotNull($penshell, 'Te Bwere/Katati missing from catalog');
        $this->assertEquals('Pinnidae', $penshell['scientific_name']);

        // Existing curated entries are preserved, not overwritten by the
        // source spellings (source: Albula glossondonta, groupers generic).
        $ikari = $byName->get('Te Ikari');
        $this->assertNotNull($ikari);
        $this->assertEquals('Albula glossodonta', $ikari['scientific_name']);
        $this->assertEquals('low', $ikari['ciguatera_risk']);
        $this->assertNotEmpty($ikari['photo_url']);

        $kuau = $byName->get('Te Kuau');
        $this->assertNotNull($kuau);
        $this->assertEquals('Epinephelus polyphekadion', $kuau['scientific_name']);
    }

    public function test_reference_species_seeder_is_idempotent()
    {
        $this->seed(\Database\Seeders\KiribatiMarineSpeciesSeeder::class);
        $this->seed(\Database\Seeders\KiribatiMarineSpeciesSeeder::class);

        $this->assertEquals(42, Species::count());
        $lowerNames = Species::pluck('kiribati_name')->map(fn ($n) => strtolower($n));
        $this->assertEquals($lowerNames->count(), $lowerNames->unique()->count());
    }

    public function test_admin_can_update_species_details()
    {
        $admin = User::where('role', 'admin')->first();
        $species = Species::where('kiribati_name', 'Te Bwere')->first();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/species/{$species->id}", [
            'kiribati_name' => 'Te Bwere (Updated)',
            'english_name' => 'Humpback Red Snapper',
            'ciguatera_risk' => 'extreme',
            'notes' => 'High clinical ciguatera risk documented in Betio channel.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('species.kiribati_name', 'Te Bwere (Updated)');
        $response->assertJsonPath('species.english_name', 'Humpback Red Snapper');
        $response->assertJsonPath('species.ciguatera_risk', 'extreme');

        $this->assertDatabaseHas('species', [
            'id' => $species->id,
            'kiribati_name' => 'Te Bwere (Updated)',
            'english_name' => 'Humpback Red Snapper',
            'ciguatera_risk' => 'extreme',
        ]);
    }

    public function test_admin_can_update_species_photo_and_visual_features()
    {
        $admin = User::where('role', 'admin')->first();
        $species = Species::where('kiribati_name', 'Te Okaoka')->first();

        $newTraits = [
            'Vivid orange stripes radiating from mouth across blue-grey cheek',
            'Serrated erectile dorsal trigger spine locked in upright posture',
            'White caudal peduncle ring with dark reticulated scales',
        ];
        $newPhoto = 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?custom=1';

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/species/{$species->id}", [
            'photo_url' => $newPhoto,
            'visual_features' => $newTraits,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('species.photo_url', $newPhoto);
        $response->assertJsonPath('species.visual_features', $newTraits);
        $response->assertJsonPath('species.ai_trained', true);

        // Verify retrieval via GET /api/species returns the updated visual features
        $getRes = $this->actingAs($admin, 'sanctum')->getJson('/api/species?search=Okaoka');
        $getRes->assertStatus(200);
        $okaoka = collect($getRes->json('data'))->firstWhere('id', $species->id);
        $this->assertEquals($newTraits, $okaoka['visual_features']);
        $this->assertEquals($newPhoto, $okaoka['photo_url']);

        // Verify AI identification with hint returns these visual features
        $aiRes = $this->actingAs($admin, 'sanctum')->postJson('/api/ai/identify-fish', [
            'image_url' => $newPhoto,
            'hint' => 'Te Okaoka',
        ]);
        $aiRes->assertStatus(200);
        $this->assertNotEmpty($aiRes->json('identification.visual_features'));
    }
}
