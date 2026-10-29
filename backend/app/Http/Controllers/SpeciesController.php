<?php

namespace App\Http\Controllers;

use App\Models\Species;
use Illuminate\Http\Request;

class SpeciesController extends Controller
{
    public function index(Request $request)
    {
        $query = Species::withCount(['catches', 'advisoryZones'])
            ->orderBy('kiribati_name', 'asc');

        // Optional search filter
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('kiribati_name', 'like', "%{$search}%")
                  ->orWhere('english_name', 'like', "%{$search}%")
                  ->orWhere('scientific_name', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Optional ciguatera risk filter
        if ($request->filled('ciguatera_risk') && $request->ciguatera_risk !== 'all') {
            $query->where('ciguatera_risk', $request->ciguatera_risk);
        }

        // Reference-database filters (P1-06): verified vs needs_review
        // records, and fish species vs broader groups vs non-fish.
        if ($request->filled('verification_status') && $request->verification_status !== 'all') {
            $query->where('verification_status', $request->verification_status);
        }

        if ($request->filled('record_type') && $request->record_type !== 'all') {
            $query->where('record_type', $request->record_type);
        }

        $species = $query->get();

        // Load AI knowledge base map indexed by lowercased Kiribati name
        $kbList = AiIdentificationController::getKnowledgeBase();
        $kbMap = [];
        foreach ($kbList as $kb) {
            $kbMap[strtolower($kb['kiribati_name'])] = $kb;
        }

        // Transform and attach AI traits & photo fallback
        $species->transform(function ($item) use ($kbMap) {
            $key = strtolower($item->kiribati_name);
            $kb = $kbMap[$key] ?? null;

            $dbFeatures = is_array($item->visual_features) ? $item->visual_features : [];

            if (!empty($dbFeatures)) {
                $item->visual_features = $dbFeatures;
                $item->ciguatera_warning = $kb['ciguatera_warning'] ?? null;
                $item->confidence_range = $kb['confidence_range'] ?? [0.88, 0.95];
                $item->ai_trained = true;
            } elseif ($kb) {
                $item->visual_features = $kb['visual_features'] ?? [];
                $item->ciguatera_warning = $kb['ciguatera_warning'] ?? null;
                $item->confidence_range = $kb['confidence_range'] ?? [0.88, 0.95];
                $item->ai_trained = true;

                if (!$item->photo_url && !empty($kb['photo_url'])) {
                    $item->photo_url = $kb['photo_url'];
                }
            } else {
                $item->visual_features = [];
                $item->ai_trained = false;
            }

            $item->is_verified_record = ($item->verification_status ?? null) === 'verified';
            $item->requires_human_review = !$item->is_verified_record;

            // Fallback to latest catch photo if still missing
            if (!$item->photo_url) {
                $latestCatch = $item->catches()->whereNotNull('photo_url')->latest()->first();
                if ($latestCatch) {
                    $item->photo_url = $latestCatch->photo_url;
                }
            }

            return $item;
        });

        return response()->json([
            'data' => $species,
            'count' => $species->count(),
            'knowledge_base_count' => count($kbList),
        ]);
    }

    /**
     * Add new verified species to catalog (Admin/Reviewer)
     * POST /api/species
     */
    public function store(Request $request)
    {
        $request->validate([
            'kiribati_name' => 'required|string|max:255',
            'english_name' => 'required|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'scientific_name_as_provided' => 'nullable|string|max:255',
            'ciguatera_risk' => 'nullable|in:low,medium,high,extreme,unknown',
            'verification_status' => 'nullable|in:verified,needs_review',
            'record_type' => 'nullable|in:species,taxonomic_group,non_fish_organism',
            'photo_url' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $species = Species::firstOrCreate(
            ['kiribati_name' => $request->kiribati_name],
            [
                'english_name' => $request->english_name,
                'scientific_name' => $request->scientific_name ?? $request->english_name,
                'scientific_name_as_provided' => $request->scientific_name_as_provided ?? $request->scientific_name ?? $request->english_name,
                'ciguatera_risk' => $request->ciguatera_risk ?? 'low',
                'verification_status' => $request->verification_status ?? 'needs_review',
                'record_type' => $request->record_type ?? 'species',
                'photo_url' => $request->photo_url,
                'notes' => $request->notes,
            ]
        );

        if (!$species->wasRecentlyCreated && $request->filled('photo_url') && !$species->photo_url) {
            $species->update(['photo_url' => $request->photo_url]);
        }

        return response()->json([
            'message' => 'Species successfully added to verified Kiribati catalog.',
            'species' => $species,
        ], 201);
    }

    /**
     * Update verified species details (Admin/Reviewer)
     * PUT /api/species/{id}
     */
    public function update(Request $request, $id)
    {
        $species = Species::findOrFail($id);

        $validated = $request->validate([
            'kiribati_name' => 'sometimes|required|string|max:255',
            'english_name' => 'sometimes|required|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'scientific_name_as_provided' => 'nullable|string|max:255',
            'ciguatera_risk' => 'nullable|in:low,medium,high,extreme,unknown,none',
            'verification_status' => 'nullable|in:verified,needs_review',
            'record_type' => 'nullable|in:species,taxonomic_group,non_fish_organism',
            'photo_url' => 'nullable|string',
            'notes' => 'nullable|string',
            'visual_features' => 'nullable|array',
            'visual_features.*' => 'string',
        ]);

        $species->update(collect($validated)->toArray());

        // Attach visual features if provided and sync with in-memory knowledge base
        $features = $request->input('visual_features');
        if (is_array($features)) {
            $found = false;
            foreach (AiIdentificationController::$speciesKnowledgeBase as $idx => $kb) {
                if (strtolower($kb['kiribati_name']) === strtolower($species->kiribati_name)) {
                    if ($species->photo_url) {
                        AiIdentificationController::$speciesKnowledgeBase[$idx]['photo_url'] = $species->photo_url;
                    }
                    AiIdentificationController::$speciesKnowledgeBase[$idx]['visual_features'] = $features;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                AiIdentificationController::$speciesKnowledgeBase[] = [
                    'kiribati_name' => $species->kiribati_name,
                    'english_name' => $species->english_name,
                    'scientific_name' => $species->scientific_name,
                    'ciguatera_risk' => $species->ciguatera_risk ?? 'low',
                    'confidence_range' => [0.88, 0.94],
                    'photo_url' => $species->photo_url,
                    'visual_features' => $features,
                    'ciguatera_warning' => null,
                ];
            }
        }

        $fresh = $species->fresh();
        $fresh->visual_features = $species->visual_features ?: ($features ?? []);
        $fresh->ai_trained = true;

        return response()->json([
            'message' => 'Species updated successfully.',
            'species' => $fresh,
        ]);
    }
}
