<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Species;
use App\Models\FishingTrip;
use App\Models\TripRoutePoint;
use App\Models\CatchRecord;
use App\Models\AdvisoryZone;
use App\Models\AdvisoryAuditLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Users
        $admin = User::firstOrCreate(
            ['email' => 'admin@fisheries.gov.ki'],
            [
                'name' => 'Ministry Admin (Fisheries HQ)',
                'password' => Hash::make('KiribatiAdmin2026!'),
                'role' => 'admin',
            ]
        );

        $reviewer = User::firstOrCreate(
            ['email' => 'reviewer@fisheries.gov.ki'],
            [
                'name' => 'Marine Biologist Reviewer',
                'password' => Hash::make('ReviewerPass2026!'),
                'role' => 'reviewer',
            ]
        );

        $fisher1 = User::firstOrCreate(
            ['email' => 'teititeki@fisher.ki'],
            [
                'name' => 'Teititeki Tabua (Betio Fisher)',
                'password' => Hash::make('FisherPass2026!'),
                'role' => 'fisher',
            ]
        );

        $fisher2 = User::firstOrCreate(
            ['email' => 'erik@fisher.ki'],
            [
                'name' => 'Erik Eita (Bairiki Artisanal)',
                'password' => Hash::make('FisherPass2026!'),
                'role' => 'fisher',
            ]
        );

        $fisher3 = User::firstOrCreate(
            ['email' => 'karotu@fisher.ki'],
            [
                'name' => 'Karotu Bwebwe (Eita Village)',
                'password' => Hash::make('FisherPass2026!'),
                'role' => 'fisher',
            ]
        );

        // 2. Species Catalog
        $atun = Species::firstOrCreate(
            ['kiribati_name' => 'Te Atun'],
            [
                'english_name' => 'Skipjack Tuna',
                'scientific_name' => 'Katsuwonus pelamis',
                'scientific_name_as_provided' => 'Katsuwonus pelamis',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'low',
                'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=700&q=80',
                'notes' => 'Abundant oceanic pelagic species, key subsistence catch.'
            ]
        );

        $bwere = Species::firstOrCreate(
            ['kiribati_name' => 'Te Bwere'],
            [
                'english_name' => 'Paddletail Snapper',
                'scientific_name' => 'Lutjanus gibbus',
                'scientific_name_as_provided' => 'Lutjanus gibbus',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'medium',
                'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
                'notes' => 'Reef dweller. Advisory caution in specific outer lagoon reefs.'
            ]
        );

        $ikari = Species::firstOrCreate(
            ['kiribati_name' => 'Te Ikari'],
            [
                'english_name' => 'Bonefish',
                'scientific_name' => 'Albula glossodonta',
                'scientific_name_as_provided' => 'Albula glossodonta',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'low',
                'photo_url' => 'https://images.unsplash.com/photo-1522069169874-c58ec4b76be5?auto=format&fit=crop&w=700&q=80',
                'notes' => 'Lagoon flat specialist, highly prized in Tarawa and Abaiang.'
            ]
        );

        $kuau = Species::firstOrCreate(
            ['kiribati_name' => 'Te Kuau'],
            [
                'english_name' => 'Marbled Grouper',
                'scientific_name' => 'Epinephelus polyphekadion',
                'scientific_name_as_provided' => 'Epinephelus polyphekadion',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'medium',
                'photo_url' => 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=700&q=80',
                'notes' => 'Coral channel predator, regulated spawning aggregation seasons.'
            ]
        );

        $okaoka = Species::firstOrCreate(
            ['kiribati_name' => 'Te Okaoka'],
            [
                'english_name' => 'Titan Triggerfish',
                'scientific_name' => 'Balistoides viridescens',
                'scientific_name_as_provided' => 'Balistoides viridescens',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'high',
                'photo_url' => 'https://images.unsplash.com/photo-1576675466969-38eeae4b41f6?w=700&q=80',
                'notes' => 'Occasional ciguatera toxicity reported in older large specimens.'
            ]
        );

        $ouna = Species::firstOrCreate(
            ['kiribati_name' => 'Te Ouna'],
            [
                'english_name' => 'Yellowfin Goatfish',
                'scientific_name' => 'Mulloidichthys vanicolensis',
                'scientific_name_as_provided' => 'Mulloidichthys vanicolensis',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'low',
                'photo_url' => 'https://images.unsplash.com/photo-1534043464124-3be32fe00099?auto=format&fit=crop&w=700&q=80',
                'notes' => 'Sandy substrate and shallow patch reefs.'
            ]
        );

        $ingimea = Species::firstOrCreate(
            ['kiribati_name' => 'Te Ingimea'],
            [
                'english_name' => 'Yellowfin Tuna',
                'scientific_name' => 'Thunnus albacares',
                'scientific_name_as_provided' => 'Thunnus albacares',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'low',
                'photo_url' => 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700&q=80',
                'notes' => 'Offshore pelagic, high economic and dietary importance.'
            ]
        );

        $baibo = Species::firstOrCreate(
            ['kiribati_name' => 'Te Baibo'],
            [
                'english_name' => 'Peacock Grouper',
                'scientific_name' => 'Cephalopholis argus',
                'scientific_name_as_provided' => 'Cephalopholis argus',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'high',
                'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
                'notes' => 'High reef toxin accumulator; health authorities advise against consumption from affected zones.'
            ]
        );

        $rereba = Species::firstOrCreate(
            ['kiribati_name' => 'Te Rereba'],
            [
                'english_name' => 'Giant Trevally',
                'scientific_name' => 'Caranx ignobilis',
                'scientific_name_as_provided' => 'Caranx ignobilis',
                'verification_status' => 'verified',
                'record_type' => 'species',
                'ciguatera_risk' => 'medium',
                'photo_url' => 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700&q=80',
                'notes' => 'Apex predator; large apex specimens (>10 kg) carry bioaccumulated CFP risk.'
            ]
        );

        // 2b. Kiribati marine reference list (35 entries, P1-06).
        // Adds any missing names from kiribati_marine_species_35.sql;
        // existing curated species above are preserved untouched.
        $this->call(KiribatiMarineSpeciesSeeder::class);

        // 3. Fishing Trips & GPS Route Points
        // Trip 1: Betio Harbor -> North Tarawa Passage -> Bikeman Reef -> Return
        $trip1 = FishingTrip::firstOrCreate(
            ['user_id' => $fisher1->id, 'vessel_name' => 'Te Raoi II'],
            [
                'started_at' => Carbon::now()->subDays(2)->setHour(5)->setMinute(30),
                'ended_at' => Carbon::now()->subDays(2)->setHour(13)->setMinute(45),
                'status' => 'completed',
                'notes' => 'Early morning trolling in South Tarawa lagoon and channel.',
            ]
        );

        $trip1Points = [
            ['lat' => 1.3582, 'lng' => 172.9315, 'seq' => 1, 'mins' => 0],   // Betio Harbor start
            ['lat' => 1.3650, 'lng' => 172.9450, 'seq' => 2, 'mins' => 30],  // West channel
            ['lat' => 1.3820, 'lng' => 172.9680, 'seq' => 3, 'mins' => 75],  // Mid-lagoon
            ['lat' => 1.4110, 'lng' => 172.9950, 'seq' => 4, 'mins' => 130], // North Bikeman
            ['lat' => 1.4350, 'lng' => 173.0200, 'seq' => 5, 'mins' => 210], // Near Buariki pass
            ['lat' => 1.3980, 'lng' => 173.0150, 'seq' => 6, 'mins' => 320], // Turning south
            ['lat' => 1.3620, 'lng' => 172.9550, 'seq' => 7, 'mins' => 420], // Approach Betio
            ['lat' => 1.3585, 'lng' => 172.9320, 'seq' => 8, 'mins' => 495], // Betio Harbor dock
        ];

        TripRoutePoint::where('trip_id', $trip1->id)->delete();
        foreach ($trip1Points as $p) {
            TripRoutePoint::create([
                'trip_id' => $trip1->id,
                'latitude' => $p['lat'],
                'longitude' => $p['lng'],
                'sequence' => $p['seq'],
                'recorded_at' => (clone $trip1->started_at)->addMinutes($p['mins']),
            ]);
        }

        // Trip 2: Bairiki Fishery Jetty -> Ambo Lagoon flats
        $trip2 = FishingTrip::firstOrCreate(
            ['user_id' => $fisher2->id, 'vessel_name' => 'Taura 1'],
            [
                'started_at' => Carbon::now()->subDay()->setHour(6)->setMinute(0),
                'ended_at' => Carbon::now()->subDay()->setHour(11)->setMinute(30),
                'status' => 'completed',
                'notes' => 'Net and handline fishing along Bairiki to Ambo reef edge.',
            ]
        );

        $trip2Points = [
            ['lat' => 1.3320, 'lng' => 172.9780, 'seq' => 1, 'mins' => 0],   // Bairiki wharf
            ['lat' => 1.3410, 'lng' => 172.9920, 'seq' => 2, 'mins' => 40],  // Nanikai reef
            ['lat' => 1.3490, 'lng' => 173.0150, 'seq' => 3, 'mins' => 110], // Teaoraereke
            ['lat' => 1.3550, 'lng' => 173.0380, 'seq' => 4, 'mins' => 180], // Ambo flat
            ['lat' => 1.3330, 'lng' => 172.9800, 'seq' => 5, 'mins' => 330], // Back to Bairiki
        ];

        TripRoutePoint::where('trip_id', $trip2->id)->delete();
        foreach ($trip2Points as $p) {
            TripRoutePoint::create([
                'trip_id' => $trip2->id,
                'latitude' => $p['lat'],
                'longitude' => $p['lng'],
                'sequence' => $p['seq'],
                'recorded_at' => (clone $trip2->started_at)->addMinutes($p['mins']),
            ]);
        }

        // 4. Catch Records with AI suggestions and human verification states
        CatchRecord::truncate();

        // Catch 1: Te Atun (Skipjack Tuna) - Pending review
        CatchRecord::create([
            'trip_id' => $trip1->id,
            'user_id' => $fisher1->id,
            'species_id' => $atun->id,
            'ai_species_suggestion' => 'Te Atun (Skipjack Tuna)',
            'ai_confidence' => 0.94,
            'ai_metadata' => ['model' => 'muse-spark-1.3', 'features' => ['dark longitudinal stripes on belly', 'torpedo shape']],
            'quantity' => 4,
            'weight_kg' => 14.50,
            'caught_at' => Carbon::now()->subDays(2)->setHour(8)->setMinute(15),
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80',
            'latitude' => 1.3820,
            'longitude' => 172.9680,
            'verification_status' => 'pending',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'verification_notes' => null,
        ]);

        // Catch 2: Te Bwere (Paddletail Snapper) - Pending review
        CatchRecord::create([
            'trip_id' => $trip2->id,
            'user_id' => $fisher2->id,
            'species_id' => $bwere->id,
            'ai_species_suggestion' => 'Te Bwere (Paddletail Snapper)',
            'ai_confidence' => 0.88,
            'ai_metadata' => ['model' => 'muse-spark-1.3', 'features' => ['red-brown body', 'dark paddle-shaped caudal fin']],
            'quantity' => 2,
            'weight_kg' => 3.80,
            'caught_at' => Carbon::now()->subDay()->setHour(8)->setMinute(45),
            'photo_url' => 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?auto=format&fit=crop&w=600&q=80',
            'latitude' => 1.3490,
            'longitude' => 173.0150,
            'verification_status' => 'pending',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'verification_notes' => null,
        ]);

        // Catch 3: Corrected by Admin: AI suggested Te Bwere, human corrected to Te Kuau (Marbled Grouper)
        CatchRecord::create([
            'trip_id' => $trip1->id,
            'user_id' => $fisher3->id,
            'species_id' => $kuau->id, // Corrected species
            'ai_species_suggestion' => 'Te Bwere (Paddletail Snapper)', // Original AI suggestion PRESERVED
            'ai_confidence' => 0.62,
            'ai_metadata' => ['model' => 'muse-spark-1.2', 'notes' => 'Low lighting in lagoon corner led to ambiguous coloration'],
            'quantity' => 1,
            'weight_kg' => 4.20,
            'caught_at' => Carbon::now()->subDays(3)->setHour(14)->setMinute(10),
            'photo_url' => 'https://images.unsplash.com/photo-1535591273668-578e31182c4f?auto=format&fit=crop&w=600&q=80',
            'latitude' => 1.4110,
            'longitude' => 172.9950,
            'verification_status' => 'corrected',
            'verified_by_user_id' => $admin->id,
            'verified_at' => Carbon::now()->subDays(1)->setHour(10)->setMinute(0),
            'verification_notes' => 'Examined pectoral fin spotting and broad head shape. Definitely Epinephelus polyphekadion, not Lutjanus gibbus.',
        ]);

        // Catch 4: Verified by Admin (Te Ikari / Bonefish)
        CatchRecord::create([
            'trip_id' => $trip2->id,
            'user_id' => $fisher1->id,
            'species_id' => $ikari->id,
            'ai_species_suggestion' => 'Te Ikari (Bonefish)',
            'ai_confidence' => 0.98,
            'ai_metadata' => ['model' => 'muse-spark-1.3', 'features' => ['silvery body', 'subterminal conical snout', 'forked caudal fin']],
            'quantity' => 8,
            'weight_kg' => 9.40,
            'caught_at' => Carbon::now()->subDays(4)->setHour(9)->setMinute(20),
            'photo_url' => 'https://images.unsplash.com/photo-1522069169874-c58ec4b76be5?auto=format&fit=crop&w=600&q=80',
            'latitude' => 1.3550,
            'longitude' => 173.0380,
            'verification_status' => 'verified',
            'verified_by_user_id' => $admin->id,
            'verified_at' => Carbon::now()->subDays(3)->setHour(16)->setMinute(0),
            'verification_notes' => 'High quality capture; perfectly conforms to lagoon bonefish morphology.',
        ]);

        // Catch 5: Te Okaoka (Titan Triggerfish) - Pending review
        CatchRecord::create([
            'trip_id' => $trip1->id,
            'user_id' => $fisher2->id,
            'species_id' => $okaoka->id,
            'ai_species_suggestion' => 'Te Okaoka (Titan Triggerfish)',
            'ai_confidence' => 0.91,
            'ai_metadata' => ['model' => 'muse-spark-1.3', 'features' => ['heavy jaw', 'black-bordered fins', 'cross-hatch scale pattern']],
            'quantity' => 1,
            'weight_kg' => 2.60,
            'caught_at' => Carbon::now()->subHours(18),
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80',
            'latitude' => 1.4350,
            'longitude' => 173.0200,
            'verification_status' => 'pending',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'verification_notes' => null,
        ]);

        // Catch 6: Flagged - Unidentifiable / Blurry image
        CatchRecord::create([
            'trip_id' => null,
            'user_id' => $fisher3->id,
            'species_id' => null,
            'ai_species_suggestion' => 'Uncertain (Reef Fish)',
            'ai_confidence' => 0.28,
            'ai_metadata' => ['model' => 'muse-spark-1.3', 'warning' => 'Low resolution / severe motion blur'],
            'quantity' => 1,
            'weight_kg' => 1.10,
            'caught_at' => Carbon::now()->subHours(12),
            'photo_url' => 'https://images.unsplash.com/photo-1518837695005-2083093ee35b?auto=format&fit=crop&w=600&q=80',
            'latitude' => 1.3410,
            'longitude' => 172.9920,
            'verification_status' => 'flagged',
            'verified_by_user_id' => $reviewer->id,
            'verified_at' => Carbon::now()->subHours(4),
            'verification_notes' => 'Image too blurry to ascertain distinguishing fin or color markings. Flagged for resubmission.',
        ]);

        // Catch 7: Te Ouna (Goatfish) - Verified
        CatchRecord::create([
            'trip_id' => $trip2->id,
            'user_id' => $fisher3->id,
            'species_id' => $ouna->id,
            'ai_species_suggestion' => 'Te Ouna (Yellowfin Goatfish)',
            'ai_confidence' => 0.93,
            'ai_metadata' => ['model' => 'muse-spark-1.3', 'features' => ['yellow longitudinal stripe', 'chin barbels visible']],
            'quantity' => 12,
            'weight_kg' => 5.75,
            'caught_at' => Carbon::now()->subDays(5)->setHour(7)->setMinute(15),
            'photo_url' => 'https://images.unsplash.com/photo-1534043464124-3be32fe00099?auto=format&fit=crop&w=600&q=80',
            'latitude' => 1.3320,
            'longitude' => 172.9780,
            'verification_status' => 'verified',
            'verified_by_user_id' => $admin->id,
            'verified_at' => Carbon::now()->subDays(4)->setHour(11)->setMinute(30),
            'verification_notes' => 'Confirmed schooling goatfish catch.',
        ]);

        // Catch 8: Te Ingimea (Yellowfin Tuna) - Pending
        CatchRecord::create([
            'trip_id' => $trip1->id,
            'user_id' => $fisher1->id,
            'species_id' => $ingimea->id,
            'ai_species_suggestion' => 'Te Ingimea (Yellowfin Tuna)',
            'ai_confidence' => 0.96,
            'ai_metadata' => ['model' => 'muse-spark-1.3', 'features' => ['bright yellow finlets', 'long second dorsal and anal fins']],
            'quantity' => 1,
            'weight_kg' => 26.40,
            'caught_at' => Carbon::now()->subHours(6),
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80',
            'latitude' => 1.3650,
            'longitude' => 172.9450,
            'verification_status' => 'pending',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'verification_notes' => null,
        ]);

        // 5. Fish Poisoning Advisory Zones (Synthetic Demonstrations for Training & Safety)
        AdvisoryZone::truncate();
        AdvisoryAuditLog::truncate();

        // Zone 1: Published Authority-Confirmed Advisory (Betio Passage & Western Reef Flat)
        $zone1 = AdvisoryZone::create([
            'title' => '[DEMO - SYNTHETIC] Western Reef Ciguatera Warning - Betio Passage',
            'description' => 'Active public health warning for predatory reef fishes caught on the western barrier flat off Betio passage. Synthetic demonstration protocol.',
            'concern' => 'Ciguatera Fish Poisoning (CFP) / Toxic Dinoflagellate Bloom',
            'evidence' => 'Betio Health Clinic registered 4 clinical CFP cases (paresthesia, cold allodynia, vomiting) following consumption of large triggerfish and snapper. Field water assay identified Gambierdiscus toxicus cell counts exceeding alert threshold (>500 cells/g macroalgae).',
            'classification' => 'authority-confirmed',
            'status' => 'published',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9150, 1.3500],
                    [172.9350, 1.3500],
                    [172.9350, 1.3700],
                    [172.9150, 1.3700],
                    [172.9150, 1.3500],
                ]],
            ],
            'source' => 'Ministry of Fisheries & Marine Resources Development (MFMRD) & Ministry of Health',
            'review_date' => Carbon::now()->addDays(20)->toDateString(),
            'created_by_user_id' => $reviewer->id,
            'approved_by_user_id' => $admin->id,
            'approved_at' => Carbon::now()->subDays(2),
            'published_at' => Carbon::now()->subDays(2),
        ]);

        // Associate multiple species with Zone 1 (Te Okaoka / Titan Triggerfish & Te Bwere / Paddletail Snapper)
        $zone1->species()->attach([
            $okaoka->id => ['risk_level' => 'extreme', 'notes' => 'High bioaccumulation risk in mature specimens (>2kg)'],
            $bwere->id => ['risk_level' => 'high', 'notes' => 'Multiple clinical case link confirmed'],
        ]);

        // Audit log for Zone 1
        AdvisoryAuditLog::create([
            'advisory_zone_id' => $zone1->id,
            'user_id' => $reviewer->id,
            'action' => 'created',
            'changes' => ['title' => $zone1->title, 'status' => 'draft', 'classification' => 'under_investigation'],
            'notes' => 'Draft advisory generated following clinical incident report #MF-2026-08',
            'ip_address' => '127.0.0.1',
            'created_at' => Carbon::now()->subDays(3),
        ]);

        AdvisoryAuditLog::create([
            'advisory_zone_id' => $zone1->id,
            'user_id' => $admin->id,
            'action' => 'published',
            'changes' => ['status' => ['old' => 'under_investigation', 'new' => 'published'], 'classification' => 'authority-confirmed'],
            'notes' => 'Formally approved and published as an active warning by Ministry Administrator following laboratory bioassay confirmation.',
            'ip_address' => '127.0.0.1',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Zone 2: Under Investigation Advisory (Bikeman Reef Lagoon Shoal)
        $zone2 = AdvisoryZone::create([
            'title' => '[DEMO - SYNTHETIC] Bikeman Patch Reef Under Investigation',
            'description' => 'Precautionary advisory undergoing laboratory bioassay after reports of benthic turf discoloration.',
            'concern' => 'Suspected Gambierdiscus Dinoflagellate Proliferation',
            'evidence' => 'Artisanal divers observed unusual benthic discoloration and lethargic herbivorous fish. Benthic brush samples dispatched to Marine Laboratory for cell counting.',
            'classification' => 'under_investigation',
            'status' => 'under_investigation',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [172.9800, 1.4000],
                    [173.0100, 1.4000],
                    [173.0100, 1.4250],
                    [172.9800, 1.4250],
                    [172.9800, 1.4000],
                ]],
            ],
            'source' => 'Marine Biology & Ecosystem Monitoring Division',
            'review_date' => Carbon::now()->addDays(7)->toDateString(),
            'created_by_user_id' => $reviewer->id,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'published_at' => null,
        ]);

        $zone2->species()->attach([
            $kuau->id => ['risk_level' => 'medium', 'notes' => 'Apex reef predator monitoring'],
        ]);

        AdvisoryAuditLog::create([
            'advisory_zone_id' => $zone2->id,
            'user_id' => $reviewer->id,
            'action' => 'created',
            'changes' => ['title' => $zone2->title, 'status' => 'under_investigation'],
            'notes' => 'Under-investigation advisory filed by Marine Biologist Reviewer pending bioassay results.',
            'ip_address' => '127.0.0.1',
            'created_at' => Carbon::now()->subDay(),
        ]);

        // Zone 3: Draft Advisory (Teaoraereke Inshore Lagoon)
        $zone3 = AdvisoryZone::create([
            'title' => '[DEMO - SYNTHETIC] Teaoraereke Shoal Draft Caution',
            'description' => 'Preliminary report awaiting field inspection and fisher interviews.',
            'concern' => 'Unverified Anecdotal Illness',
            'evidence' => 'Verbal inquiry received from village council regarding fish caught near Teaoraereke bridge. No hospital admissions confirmed.',
            'classification' => 'suspected',
            'status' => 'draft',
            'polygon_geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [173.0050, 1.3400],
                    [173.0250, 1.3400],
                    [173.0250, 1.3550],
                    [173.0050, 1.3550],
                    [173.0050, 1.3400],
                ]],
            ],
            'source' => 'Community Fisheries Officer',
            'review_date' => Carbon::now()->addDays(14)->toDateString(),
            'created_by_user_id' => $admin->id,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'published_at' => null,
        ]);

        $zone3->species()->attach([
            $bwere->id => ['risk_level' => 'low', 'notes' => 'Precautionary surveillance'],
        ]);

        AdvisoryAuditLog::create([
            'advisory_zone_id' => $zone3->id,
            'user_id' => $admin->id,
            'action' => 'created',
            'changes' => ['title' => $zone3->title, 'status' => 'draft'],
            'notes' => 'Draft established awaiting officer field inspection.',
            'ip_address' => '127.0.0.1',
            'created_at' => Carbon::now()->subHours(8),
        ]);
    }
}
