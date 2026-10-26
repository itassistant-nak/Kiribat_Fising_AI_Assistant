<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Species;
use App\Models\FishingTrip;
use App\Models\TripRoutePoint;
use App\Models\CatchRecord;
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
                'ciguatera_risk' => 'low',
                'notes' => 'Abundant oceanic pelagic species, key subsistence catch.'
            ]
        );

        $bwere = Species::firstOrCreate(
            ['kiribati_name' => 'Te Bwere'],
            [
                'english_name' => 'Paddletail Snapper',
                'scientific_name' => 'Lutjanus gibbus',
                'ciguatera_risk' => 'medium',
                'notes' => 'Reef dweller. Advisory caution in specific outer lagoon reefs.'
            ]
        );

        $ikari = Species::firstOrCreate(
            ['kiribati_name' => 'Te Ikari'],
            [
                'english_name' => 'Bonefish',
                'scientific_name' => 'Albula glossodonta',
                'ciguatera_risk' => 'low',
                'notes' => 'Lagoon flat specialist, highly prized in Tarawa and Abaiang.'
            ]
        );

        $kuau = Species::firstOrCreate(
            ['kiribati_name' => 'Te Kuau'],
            [
                'english_name' => 'Marbled Grouper',
                'scientific_name' => 'Epinephelus polyphekadion',
                'ciguatera_risk' => 'medium',
                'notes' => 'Coral channel predator, regulated spawning aggregation seasons.'
            ]
        );

        $okaoka = Species::firstOrCreate(
            ['kiribati_name' => 'Te Okaoka'],
            [
                'english_name' => 'Titan Triggerfish',
                'scientific_name' => 'Balistoides viridescens',
                'ciguatera_risk' => 'high',
                'notes' => 'Occasional ciguatera toxicity reported in older large specimens.'
            ]
        );

        $ouna = Species::firstOrCreate(
            ['kiribati_name' => 'Te Ouna'],
            [
                'english_name' => 'Yellowfin Goatfish',
                'scientific_name' => 'Mulloidichthys vanicolensis',
                'ciguatera_risk' => 'low',
                'notes' => 'Sandy substrate and shallow patch reefs.'
            ]
        );

        $ingimea = Species::firstOrCreate(
            ['kiribati_name' => 'Te Ingimea'],
            [
                'english_name' => 'Yellowfin Tuna',
                'scientific_name' => 'Thunnus albacares',
                'ciguatera_risk' => 'low',
                'notes' => 'Offshore pelagic, high economic and dietary importance.'
            ]
        );

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
    }
}
