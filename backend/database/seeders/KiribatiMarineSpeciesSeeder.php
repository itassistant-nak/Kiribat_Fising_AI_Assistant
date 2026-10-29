<?php

namespace Database\Seeders;

use App\Models\Species;
use Illuminate\Database\Seeder;

/**
 * Imports the 35-entry Kiribati marine reference list
 * (kiribati_marine_species_35.sql at the project root) into the
 * `species` catalog.
 *
 * Source rows are imported verbatim: the scientific name is stored
 * exactly as provided (including "Multiple species", "-" and known
 * misspellings), and every new entry is marked needs_review with
 * ciguatera_risk "unknown" — no risk, photo or visual traits are
 * invented. Kiribati names are display-cased to match the existing
 * catalog ("Te Bwere"); the verbatim source name is kept in notes.
 *
 * Entries whose Kiribati name already exists (case-insensitive) are
 * left untouched, so the curated species keep their photos, risks
 * and catch links — except rows previously imported by this seeder
 * (recognised by their import note), whose structured reference
 * fields are backfilled so upgraded databases converge. Safe to
 * re-run: it never creates duplicates.
 */
class KiribatiMarineSpeciesSeeder extends Seeder
{
    /**
     * [source id, kiribati_name, common_name, scientific_name_as_provided,
     *  review_notes, record_type]
     * Transcribed verbatim from kiribati_marine_species_35.sql.
     * record_type: species (binomial fish), taxonomic_group (Multiple
     * species / family / genus-only / broad reef/pelagic categories),
     * non_fish_organism (shells, clams, crab, octopus, jellyfish, worm).
     */
    private const ROWS = [
        [1, 'Te Amori', 'longtail silver biddy', 'Gerres longirostris', '', 'species'],
        [2, 'Te Ati', 'skipjack tuna', 'Katsuwonus pelamis', '', 'species'],
        [3, 'Te Aua', 'mullet', 'Multiple species (Muglidae)', 'Family spelling in source may be Mugilidae; verify', 'taxonomic_group'],
        [4, 'Te Baiku', 'stingrays', 'Multiple species', '', 'taxonomic_group'],
        [5, 'Bai Tabaa', 'squaretail mullet (juvenile)', 'Liza vaigiensis', 'Check currently accepted taxonomy', 'species'],
        [6, 'Te Baiura', 'yellowfin tuna', 'Thunnus albacares', '', 'species'],
        [7, 'Te Baneawa', 'milkfish', 'Chanos chanos', '', 'species'],
        [8, 'Te Bawe', 'blacktail snapper', 'Lutjanus fulvus', '', 'species'],
        [9, 'Te Bun', 'ark shell', 'Anadara uropigmelana', 'Scientific spelling requires verification', 'non_fish_organism'],
        [10, 'Te Bunii', 'pufferfish', 'Multiple species', '', 'taxonomic_group'],
        [11, 'Te Buuroo', 'cowrie shells', 'Multiple species (Cypraeidae)', '', 'non_fish_organism'],
        [12, 'Te Bwaitari', 'jellyfish', 'Multiple species', '', 'non_fish_organism'],
        [13, 'Te Bwaua', 'mullet', 'Multiple species (Muglidae)', 'Family spelling in source may be Mugilidae; verify', 'taxonomic_group'],
        [14, 'Te Bwaweina', 'blackspot snapper', 'Lutjanus fulviflamma', '', 'species'],
        [15, 'Te Bwere/Katati', 'penshell', 'Pinnidae', '', 'non_fish_organism'],
        [16, 'Te Iaia', 'egg mass of seahares', '-', '', 'non_fish_organism'],
        [17, 'Te Ibo', 'peanut worm', 'Sipunculus indicus', '', 'non_fish_organism'],
        [18, 'Te Ikanibong', 'humpback red snapper', 'Lutjanus gibbus', '', 'species'],
        [19, 'Te Ika N Aonora', 'reef or lagoonal fish', 'Multiple species', '', 'taxonomic_group'],
        [20, 'Te Ika N Marawa', 'pelagic fish (e.g. tuna, billfish)', 'Multiple species (pelagics)', '', 'taxonomic_group'],
        [21, 'Ikan Te Nama', 'lagoon fish', 'Multiple species', '', 'taxonomic_group'],
        [22, 'Te Ikan Te Rakai', 'reef fish', 'Multiple species', '', 'taxonomic_group'],
        [23, 'Te Ikari', 'bonefish', 'Albula glossondonta', '', 'species'],
        [24, 'Te Ika Uraura', 'red grouper species', 'Plectropomus species', '', 'taxonomic_group'],
        [25, 'Te Ingo', 'two spot red snapper', 'Lutjanus bohar', '', 'species'],
        [26, 'Te Kamakama', 'crab', 'Multiple species', '', 'non_fish_organism'],
        [27, 'Te Karon', 'humphead wrasse', 'Cheilinus undulatus', '', 'species'],
        [28, 'Te Kataawa', 'striped surgeonfish', 'Acanthurus lineatus', '', 'species'],
        [29, 'Te Nikatona', 'clam', 'Quidnipagus palatam', 'Scientific spelling requires verification', 'non_fish_organism'],
        [30, 'Te Katura', 'clam', 'Mesodesma striata', '', 'non_fish_organism'],
        [31, 'Te Kiika', 'octopus', 'Octopus cyanea', '', 'non_fish_organism'],
        [32, 'Te Koikoi', 'clam', 'Asaphis violascens', '', 'non_fish_organism'],
        [33, 'Te Koinawa', 'convict surgeonfish', 'Acanthurus triostegus', '', 'species'],
        [34, 'Te Koumwara', 'Pacific asaphis', 'Gafrarium pectinatum', '', 'non_fish_organism'],
        [35, 'Te Kuau', 'groupers', 'Multiple species', '', 'taxonomic_group'],
    ];

    public function run(): void
    {
        foreach (self::ROWS as [$sourceId, $kiribatiName, $commonName, $scientificName, $reviewNotes, $recordType]) {
            $existing = Species::query()
                ->whereRaw('LOWER(kiribati_name) = ?', [strtolower($kiribatiName)])
                ->first();

            if ($existing) {
                // Backfill structured fields only for rows this seeder
                // previously imported; curated rows stay untouched.
                if (str_contains((string) $existing->notes, "kiribati_marine_species_35.sql #{$sourceId}")) {
                    $existing->forceFill([
                        'scientific_name_as_provided' => $scientificName,
                        'verification_status' => 'needs_review',
                        'record_type' => $recordType,
                    ])->save();
                }
                continue;
            }

            $notes = "Imported from kiribati_marine_species_35.sql #{$sourceId} "
                . '(i_kiribati_name: ' . strtolower($kiribatiName) . '); '
                . 'verification_status=needs_review; scientific_name_as_provided '
                . 'imported verbatim — taxonomy requires human review before '
                . 'treating as a verified species.';

            if ($reviewNotes !== '') {
                $notes .= ' Review note: ' . $reviewNotes;
            }

            Species::create([
                'kiribati_name' => $kiribatiName,
                'english_name' => ucfirst($commonName),
                'scientific_name' => $scientificName,
                'scientific_name_as_provided' => $scientificName,
                'ciguatera_risk' => 'unknown',
                'verification_status' => 'needs_review',
                'record_type' => $recordType,
                'photo_url' => null,
                'visual_features' => null,
                'notes' => $notes,
            ]);
        }
    }
}
