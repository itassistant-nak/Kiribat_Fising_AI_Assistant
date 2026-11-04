<?php

namespace App\Http\Controllers;

use App\Models\Species;
use App\Services\Concerns\ResolvesApiEndpoints;
use App\Services\FishIdentificationComparisonService;
use App\Services\MetaAiService;
use App\Services\OpenAiFishVisionService;
use App\Services\OpenAiVisionService;
use App\Services\SamSegmentationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiIdentificationController extends Controller
{
    use ResolvesApiEndpoints;

    protected MetaAiService $metaAiService;
    protected SamSegmentationService $samService;
    protected OpenAiVisionService $openAiService;
    protected OpenAiFishVisionService $openAiFishVisionService;
    protected FishIdentificationComparisonService $comparisonService;

    public function __construct(
        MetaAiService $metaAiService,
        SamSegmentationService $samService,
        OpenAiVisionService $openAiService,
        OpenAiFishVisionService $openAiFishVisionService,
        FishIdentificationComparisonService $comparisonService
    ) {
        $this->metaAiService            = $metaAiService;
        $this->samService               = $samService;
        $this->openAiService            = $openAiService;
        $this->openAiFishVisionService  = $openAiFishVisionService;
        $this->comparisonService        = $comparisonService;
    }


    /**
     * Kiribati Species Morphological Knowledge Base for AI Vision Inference
     */
    public static array $speciesKnowledgeBase = [
        [
            'kiribati_name' => 'Te Bwere',
            'english_name' => 'Paddletail Snapper',
            'scientific_name' => 'Lutjanus gibbus',
            'ciguatera_risk' => 'high',
            'confidence_range' => [0.89, 0.96],
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
            'visual_features' => [
                'Distinctive paddle-shaped rounded caudal (tail) fin with white rear margin',
                'Deep reddish-pink to orange-scarlet body coloration with steeper forehead profile',
                'Large dark eyes with prominent opercular notch above pectoral fin base',
            ],
            'ciguatera_warning' => 'High CFP bioaccumulation risk reported around South Tarawa outer passage reef drop-offs.',
        ],
        [
            'kiribati_name' => 'Te Ingimea',
            'english_name' => 'Yellowfin Tuna',
            'scientific_name' => 'Thunnus albacares',
            'ciguatera_risk' => 'low',
            'confidence_range' => [0.93, 0.98],
            'photo_url' => 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700&q=80',
            'visual_features' => [
                'Bright yellow second dorsal and anal fins extending sickle-like backwards',
                'Metallic dark blue dorsal body shading to silvery-white belly with broken vertical lines',
                'Row of vibrant yellow finlets with black edges running along caudal peduncle',
            ],
            'ciguatera_warning' => 'Pelagic species; generally safe from ciguatoxins (low bioaccumulation).',
        ],
        [
            'kiribati_name' => 'Te Okaoka',
            'english_name' => 'Titan Triggerfish',
            'scientific_name' => 'Balistoides viridescens',
            'ciguatera_risk' => 'high',
            'confidence_range' => [0.91, 0.97],
            'photo_url' => 'https://images.unsplash.com/photo-1576675466969-38eeae4b41f6?w=700&q=80',
            'visual_features' => [
                'Heavy rhomboidal body with robust beak-like jaws and prominent canine teeth',
                'Dark mustache-like band across upper lip with black reticulated scale centers',
                'First dorsal fin modified into strong erectile locking trigger spine',
            ],
            'ciguatera_warning' => 'Very high CFP risk in mature reef specimens; known coral and echinoderm feeder.',
        ],
        [
            'kiribati_name' => 'Te Kuau',
            'english_name' => 'Blacktip Grouper',
            'scientific_name' => 'Epinephelus fasciatus',
            'ciguatera_risk' => 'medium',
            'confidence_range' => [0.88, 0.95],
            'photo_url' => 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=700&q=80',
            'visual_features' => [
                'Pale red to orange body with 5-6 broad vertical dark orange-red bars',
                'Prominent jet-black tips on spinous dorsal fin membrane margins',
                'Large mouth with robust lower jaw protruding slightly forward',
            ],
            'ciguatera_warning' => 'Moderate ciguatera risk in lagoons; caution advised on specimens exceeding 1.5 kg.',
        ],
        [
            'kiribati_name' => 'Te Atun',
            'english_name' => 'Skipjack Tuna',
            'scientific_name' => 'Katsuwonus pelamis',
            'ciguatera_risk' => 'low',
            'confidence_range' => [0.91, 0.97],
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=700&q=80',
            'visual_features' => [
                'Robust streamlined torpedo-shaped dark blue to purple body',
                'Silvery belly with 4 to 6 distinct longitudinal dark stripes',
                'Deeply forked crescent caudal tail fin for high-speed open pelagic swimming',
            ],
            'ciguatera_warning' => 'Oceanic migratory pelagic; safe from reef ciguatoxins.',
        ],
        [
            'kiribati_name' => 'Te Ikari',
            'english_name' => 'Bonefish',
            'scientific_name' => 'Albula glossodonta',
            'ciguatera_risk' => 'low',
            'confidence_range' => [0.90, 0.96],
            'photo_url' => 'https://images.unsplash.com/photo-1522069169874-c58ec4b76be5?auto=format&fit=crop&w=700&q=80',
            'visual_features' => [
                'Slender silvery-white body with conical pig-like downturned snout',
                'Small mouth beneath projecting snout adapted for rooting in lagoon sandflats',
                'Deeply forked translucent caudal fin with subtle yellow shading',
            ],
            'ciguatera_warning' => 'Lagoon flat bottom feeder; universally safe subsistence and game fish.',
        ],
        [
            'kiribati_name' => 'Te Ouna',
            'english_name' => 'Yellowfin Goatfish',
            'scientific_name' => 'Mulloidichthys vanicolensis',
            'ciguatera_risk' => 'low',
            'confidence_range' => [0.89, 0.95],
            'photo_url' => 'https://images.unsplash.com/photo-1534043464124-3be32fe00099?auto=format&fit=crop&w=700&q=80',
            'visual_features' => [
                'Pair of prominent sensory chin barbels used to probe sandy lagoon flats',
                'Bright yellow dorsal, anal, and caudal fins with distinctive lateral yellow stripe',
                'Elongate cylindrical body with silvery-white underside',
            ],
            'ciguatera_warning' => 'Shallow sand/coral associate; non-toxic staple catch.',
        ],
        [
            'kiribati_name' => 'Te Baibo',
            'english_name' => 'Peacock Grouper',
            'scientific_name' => 'Cephalopholis argus',
            'ciguatera_risk' => 'high',
            'confidence_range' => [0.87, 0.94],
            'photo_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
            'visual_features' => [
                'Dark brown to olive-black body covered with hundreds of small neon-blue spots with black rings',
                'Five to six pale vertical bars across rear portion of body',
                'Large dark pectoral fins with narrow blue outer margins',
            ],
            'ciguatera_warning' => 'High reef toxin accumulator; health authorities advise against consumption from affected zones.',
        ],
        [
            'kiribati_name' => 'Te Rereba',
            'english_name' => 'Giant Trevally',
            'scientific_name' => 'Caranx ignobilis',
            'ciguatera_risk' => 'medium',
            'confidence_range' => [0.92, 0.97],
            'photo_url' => 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700&q=80',
            'visual_features' => [
                'Steep head profile with massive blunt snout and deeply forked tail',
                'Silvery-grey to dusky black coloration on upper flank, lighter beneath',
                'Curved lateral line with heavy sharp scutes along caudal peduncle',
            ],
            'ciguatera_warning' => 'Apex predator; large apex specimens (>10 kg) carry bioaccumulated CFP risk.',
        ],
    ];

    public static function getKnowledgeBase(): array
    {
        return self::$speciesKnowledgeBase;
    }

    /**
     * AI Vision Fish Identification Endpoint
     * POST /api/ai/identify-fish
     */
    public function identify(Request $request)
    {
        $request->validate([
            'image' => 'nullable', // Can be file upload, base64 data URL, or string URL
            'image_url' => 'nullable|string',
            'hint' => 'nullable|string',
        ]);

        $imagePath = null;
        $imageDataUrl = null;
        $uploadedLocalPath = null;

        // Check if an uploaded image file was passed
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = 'fish_' . time() . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/catches'), $filename);
            $uploadedLocalPath = public_path('uploads/catches/' . $filename);
            $imagePath = url('uploads/catches/' . $filename);
        } elseif ($request->filled('image_url')) {
            $imagePath = $request->input('image_url');
        } elseif ($request->filled('image') && is_string($request->input('image'))) {
            $raw = $request->input('image');
            if (str_starts_with($raw, 'data:image')) {
                // Base64 data URL
                $imageDataUrl = $raw;
                $imagePath = $raw;
            } else {
                $imagePath = $raw;
            }
        }

        // If no image provided at all, return validation error
        if (!$imagePath && !$imageDataUrl) {
            return response()->json([
                'message' => 'Please provide a fish photograph for AI identification (file upload or image URL).',
                'error' => 'MISSING_IMAGE',
            ], 422);
        }

        // Resolve image bytes to data URL only if a Meta service is configured (avoids unmocked network fetch when unconfigured)
        $dataUrl = ($this->metaAiService->isConfigured() || $this->samService->isConfigured())
            ? $this->resolveImageDataUrl($imageDataUrl, $uploadedLocalPath, $imagePath)
            : null;

        // SAM 3.1 segmentation evidence (if configured). SAM confirms and
        // isolates fish; it never names species and never blocks a result.
        $segmentation = [
            'provider'          => 'Meta SAM 3.1 (Meta Model API)',
            'model'             => $this->samService->getModel(),
            'status'            => 'unconfigured',
            'fish_detected'     => null,
            'object_count'      => 0,
            'best_box'          => null,
            'mask_coverage_max' => null,
        ];
        $segmentationRaw     = null;
        $segmentationWarning = null;
        $samResult           = [];

        if ($this->samService->isConfigured()) {
            if ($dataUrl !== null) {
                $seg = $this->samService->segment($dataUrl, 'fish');
                $samResult = $seg;
                $segmentation = array_merge($segmentation, [
                    'status'            => $seg['status'],
                    'fish_detected'     => $seg['status'] === 'ok' ? $seg['fish_detected'] : null,
                    'object_count'      => $seg['object_count'],
                    'best_box'          => $seg['best_box'],
                    'mask_coverage_max' => $seg['mask_coverage_max'],
                    'error'             => $seg['error'],
                ]);
                $segmentationRaw = $seg['raw_output'];
                if ($seg['status'] === 'ok' && !$seg['fish_detected']) {
                    $segmentationWarning = 'SAM 3.1 segmented no fish in this photo — the image may not contain a fish specimen.';
                }
            } else {
                $segmentation['status'] = 'image_unavailable';
                $segmentation['error']  = 'Could not load image bytes for segmentation.';
                $samResult = ['status' => 'image_unavailable'];
            }
        }

        // ── Provider Selection: User selects AI engine (OpenAI, Meta, or Parallel Comparison) ──
        $requestedProvider = strtolower(trim((string) $request->input('provider', $request->input('selected_provider', $request->input('model_provider', 'auto')))));
        $isCompareMode = in_array($requestedProvider, ['compare', 'parallel', 'both', 'all'])
            || $request->boolean('compare')
            || $request->input('mode') === 'parallel';

        // ── 1. User Explicitly Selected OpenAI Vision ──
        if (in_array($requestedProvider, ['openai', 'gpt-4o', 'chatgpt'])) {
            $imageForVision = $dataUrl ?: $imagePath;
            $dbReferenceSpecies = Species::whereIn('record_type', ['species'])
                ->get(['id', 'kiribati_name', 'english_name', 'scientific_name', 'ciguatera_risk'])
                ->toArray();
            if (empty($dbReferenceSpecies)) {
                $dbReferenceSpecies = self::$speciesKnowledgeBase;
            }

            $openAiRaw = $this->openAiFishVisionService->identifyFishVision(
                $imageForVision,
                $dbReferenceSpecies,
                (string) $request->input('hint', '')
            );

            $normalized = $this->comparisonService->normalizeOpenAiResponse($openAiRaw);
            $offlineCatalogue = $this->comparisonService->generateOfflineCatalogueReference(
                (string) $request->input('hint', ''),
                $dbReferenceSpecies
            );

            $isSuccess = (($normalized['status'] ?? '') === 'success');

            $matchedDbSpecies = null;
            if ($isSuccess && !empty($normalized['scientific_name'])) {
                $matchedDbSpecies = Species::where('scientific_name', $normalized['scientific_name'])
                    ->orWhere('scientific_name_as_provided', $normalized['scientific_name'])
                    ->first();
            }

            $kName = $isSuccess ? ($normalized['suggested_species']['kiribati_name'] ?? ($matchedDbSpecies->kiribati_name ?? ($normalized['suggested_species']['common_name'] ?? 'Unknown'))) : null;
            $eName = $isSuccess ? ($normalized['suggested_species']['common_name'] ?? ($matchedDbSpecies->english_name ?? 'Fish')) : null;
            $sName = $isSuccess ? ($normalized['scientific_name'] ?? ($matchedDbSpecies->scientific_name ?? 'Unknown')) : null;
            $conf  = $isSuccess ? ($normalized['confidence'] ?? null) : null;

            $candidates = [];
            if ($isSuccess && !empty($normalized['alternatives'])) {
                foreach ($normalized['alternatives'] as $alt) {
                    $candidates[] = [
                        'kiribati_name'   => $alt['kiribati_name'] ?? null,
                        'english_name'    => $alt['common_name'] ?? ($alt['english_name'] ?? ''),
                        'scientific_name' => $alt['scientific_name'] ?? '',
                        'confidence'      => $alt['confidence'] ?? null,
                    ];
                }
            }

            $primaryVerification = $matchedDbSpecies->verification_status ?? 'unverified';
            $primaryRecordType   = $matchedDbSpecies->record_type ?? 'species';

            return response()->json([
                'status'            => $isSuccess ? 'success' : ($normalized['status'] ?? 'unavailable'),
                'selected_provider' => 'openai',
                'comparison_status' => $isSuccess ? 'single_provider_result' : 'both_failed',
                'providers'         => [
                    'openai' => $normalized,
                ],
                'offline_catalogue' => $offlineCatalogue,
                'image_url'         => $imagePath,
                'identification'    => [
                    'species_id'                  => $matchedDbSpecies ? $matchedDbSpecies->id : null,
                    'kiribati_name'               => $kName,
                    'english_name'                => $eName,
                    'scientific_name'             => $sName,
                    'scientific_name_as_provided' => $matchedDbSpecies->scientific_name_as_provided ?? $sName,
                    'verification_status'         => $primaryVerification,
                    'record_type'                 => $primaryRecordType,
                    'is_verified_record'          => $primaryVerification === 'verified',
                    'confidence'                  => $conf,
                    'confidence_percentage'       => $conf !== null ? (number_format($conf * 100, 1) . '%') : 'Not available',
                    'ciguatera_risk'              => $isSuccess ? ($normalized['suggested_species']['ciguatera_risk'] ?? ($matchedDbSpecies->ciguatera_risk ?? 'low')) : 'unknown',
                    'ciguatera_warning'           => ($matchedDbSpecies && in_array($matchedDbSpecies->ciguatera_risk, ['high', 'extreme']))
                        ? 'High CFP risk reported in coastal reef zones.'
                        : null,
                    'visual_features'             => $isSuccess ? ($normalized['visible_features'] ?? []) : [],
                    'model'                       => 'OpenAI Vision (' . ($normalized['model'] ?? 'gpt-4o') . ')',
                    'model_version'               => $normalized['model'] ?? 'gpt-4o',
                    'analyzed_at'                 => now()->toIso8601String(),
                    'candidates'                  => array_slice($candidates, 0, 3),
                    'openai_model'                => [
                        'status'    => $normalized['status'],
                        'model'     => $normalized['model'] ?? 'gpt-4o',
                        'reasoning' => $normalized['reasoning'] ?? null,
                    ],
                    'requires_human_confirmation' => true,
                    'message'                     => $isSuccess ? null : 'No live AI identification was performed. OpenAI Vision is currently unavailable.',
                    'segmentation'                => $segmentation,
                    'segmentation_warning'        => $segmentationWarning,
                ],
                'segmentation_raw'  => $segmentationRaw,
                'disclaimer'        => $isSuccess
                    ? 'AI identification is an advisory suggestion based on visible morphological characteristics. Photographs cannot detect marine toxins or ciguatera. Species identity and safety decisions require human expert confirmation.'
                    : 'Live AI identification is currently unavailable. Photographs cannot detect marine toxins or ciguatera. Species identity and safety decisions require human expert confirmation.',
            ]);
        }

        // ── 2. User Explicitly Selected Meta AI / SAM 3.1 ──
        if (in_array($requestedProvider, ['meta', 'muse_glimmer', 'glimmer', 'sam', 'sam-3.1', 'sam_3.1', 'meta_sam'])) {
            $imageForVision = $dataUrl ?: $imagePath;
            $dbReferenceSpecies = Species::whereIn('record_type', ['species'])
                ->get(['id', 'kiribati_name', 'english_name', 'scientific_name', 'ciguatera_risk'])
                ->toArray();
            if (empty($dbReferenceSpecies)) {
                $dbReferenceSpecies = self::$speciesKnowledgeBase;
            }

            $museRaw = $this->metaAiService->identifyFishVision(
                imageUrlOrDataUrl: $imageForVision,
                referenceSpecies: $dbReferenceSpecies,
                samEvidence: $samResult,
                hint: (string) $request->input('hint', '')
            );

            $normalized = $this->comparisonService->normalizeMuseResponse($museRaw, null, 0, $samResult);
            $offlineCatalogue = $this->comparisonService->generateOfflineCatalogueReference(
                (string) $request->input('hint', ''),
                $dbReferenceSpecies
            );

            $isSuccess = (($normalized['status'] ?? '') === 'success');

            $matchedDbSpecies = null;
            if ($isSuccess && !empty($normalized['scientific_name'])) {
                $matchedDbSpecies = Species::where('scientific_name', $normalized['scientific_name'])
                    ->orWhere('scientific_name_as_provided', $normalized['scientific_name'])
                    ->first();
            }

            $kName = $isSuccess ? ($normalized['suggested_species']['kiribati_name'] ?? ($matchedDbSpecies->kiribati_name ?? ($normalized['suggested_species']['common_name'] ?? 'Unknown'))) : null;
            $eName = $isSuccess ? ($normalized['suggested_species']['common_name'] ?? ($matchedDbSpecies->english_name ?? 'Fish')) : null;
            $sName = $isSuccess ? ($normalized['scientific_name'] ?? ($matchedDbSpecies->scientific_name ?? 'Unknown')) : null;
            $conf  = $isSuccess ? ($normalized['confidence'] ?? null) : null;

            $candidates = [];
            if ($isSuccess && !empty($normalized['alternatives'])) {
                foreach ($normalized['alternatives'] as $alt) {
                    $candidates[] = [
                        'kiribati_name'   => $alt['kiribati_name'] ?? null,
                        'english_name'    => $alt['common_name'] ?? ($alt['english_name'] ?? ''),
                        'scientific_name' => $alt['scientific_name'] ?? '',
                        'confidence'      => $alt['confidence'] ?? null,
                    ];
                }
            }

            $primaryVerification = $matchedDbSpecies->verification_status ?? 'unverified';
            $primaryRecordType   = $matchedDbSpecies->record_type ?? 'species';

            return response()->json([
                'status'            => $isSuccess ? 'success' : ($normalized['status'] ?? 'unavailable'),
                'selected_provider' => 'muse_glimmer',
                'comparison_status' => $isSuccess ? 'single_provider_result' : 'both_failed',
                'providers'         => [
                    'muse_glimmer' => $normalized,
                ],
                'offline_catalogue' => $offlineCatalogue,
                'image_url'         => $imagePath,
                'identification'    => [
                    'species_id'                  => $matchedDbSpecies ? $matchedDbSpecies->id : null,
                    'kiribati_name'               => $kName,
                    'english_name'                => $eName,
                    'scientific_name'             => $sName,
                    'scientific_name_as_provided' => $matchedDbSpecies->scientific_name_as_provided ?? $sName,
                    'verification_status'         => $primaryVerification,
                    'record_type'                 => $primaryRecordType,
                    'is_verified_record'          => $primaryVerification === 'verified',
                    'confidence'                  => $conf,
                    'confidence_percentage'       => $conf !== null ? (number_format($conf * 100, 1) . '%') : 'Not available',
                    'ciguatera_risk'              => $isSuccess ? ($normalized['suggested_species']['ciguatera_risk'] ?? ($matchedDbSpecies->ciguatera_risk ?? 'low')) : 'unknown',
                    'ciguatera_warning'           => ($matchedDbSpecies && in_array($matchedDbSpecies->ciguatera_risk, ['high', 'extreme']))
                        ? 'High CFP risk reported in coastal reef zones.'
                        : null,
                    'visual_features'             => $isSuccess ? ($normalized['visible_features'] ?? []) : [],
                    'model'                       => ($normalized['model'] ?? 'Meta AI') . ' (' . ($normalized['model_identifier'] ?? 'sam-3.1') . ')',
                    'model_version'               => $normalized['model_identifier'] ?? ($normalized['model'] ?? 'sam-3.1'),
                    'analyzed_at'                 => now()->toIso8601String(),
                    'candidates'                  => array_slice($candidates, 0, 3),
                    'meta_model'                  => [
                        'status'    => $normalized['status'],
                        'model'     => $normalized['model_identifier'] ?? ($normalized['model'] ?? 'sam-3.1'),
                        'reasoning' => $normalized['reasoning'] ?? null,
                    ],
                    'requires_human_confirmation' => true,
                    'message'                     => $isSuccess ? null : 'No live AI identification was performed. Meta AI provider is currently unavailable.',
                    'segmentation'                => $segmentation,
                    'segmentation_warning'        => $segmentationWarning,
                ],
                'segmentation_raw'  => $segmentationRaw,
                'disclaimer'        => $isSuccess
                    ? 'AI identification is an advisory suggestion based on visible morphological characteristics. Photographs cannot detect marine toxins or ciguatera. Species identity and safety decisions require human expert confirmation.'
                    : 'Live AI identification is currently unavailable. Photographs cannot detect marine toxins or ciguatera. Species identity and safety decisions require human expert confirmation.',
            ]);
        }

        // ── 3. Parallel Comparison Mode: Muse Glimmer + OpenAI Vision ──────────────
        if ($isCompareMode) {
            $imageForVision = $dataUrl ?: $imagePath;
            $dbReferenceSpecies = Species::whereIn('record_type', ['species'])
                ->get(['id', 'kiribati_name', 'english_name', 'scientific_name', 'ciguatera_risk'])
                ->toArray();
            if (empty($dbReferenceSpecies)) {
                $dbReferenceSpecies = self::$speciesKnowledgeBase;
            }

            $comparisonResult = $this->comparisonService->compare(
                imageUrlOrDataUrl: $imageForVision,
                referenceSpecies: $dbReferenceSpecies,
                hint: (string) $request->input('hint', ''),
                samEvidence: $samResult
            );

            $persistedIds = $this->comparisonService->persistComparison(
                $comparisonResult,
                $request->input('catch_id') ? (int) $request->input('catch_id') : null
            );

            $museProv   = $comparisonResult['providers']['muse_glimmer'] ?? [];
            $openAiProv = $comparisonResult['providers']['openai'] ?? [];

            // Primary selection for legacy identification block (successes only!)
            $primaryProvider = null;
            if (($museProv['status'] ?? '') === 'success' && ($openAiProv['status'] ?? '') === 'success') {
                $primaryProvider = (($museProv['confidence'] ?? 0) >= ($openAiProv['confidence'] ?? 0))
                    ? $museProv
                    : $openAiProv;
            } elseif (($museProv['status'] ?? '') === 'success') {
                $primaryProvider = $museProv;
            } elseif (($openAiProv['status'] ?? '') === 'success') {
                $primaryProvider = $openAiProv;
            }

            $hasSuccess = ($primaryProvider !== null);

            $matchedDbSpecies = null;
            if ($hasSuccess && !empty($primaryProvider['species_id'])) {
                $matchedDbSpecies = Species::find($primaryProvider['species_id']);
            } elseif ($hasSuccess && !empty($primaryProvider['scientific_name'])) {
                $matchedDbSpecies = Species::where('scientific_name', $primaryProvider['scientific_name'])->first();
            }

            $kName = $hasSuccess ? ($primaryProvider['suggested_species']['kiribati_name'] ?? ($matchedDbSpecies->kiribati_name ?? ($primaryProvider['suggested_species']['common_name'] ?? 'Unknown'))) : null;
            $eName = $hasSuccess ? ($primaryProvider['suggested_species']['common_name'] ?? ($matchedDbSpecies->english_name ?? 'Fish')) : null;
            $sName = $hasSuccess ? ($primaryProvider['scientific_name'] ?? ($matchedDbSpecies->scientific_name ?? 'Unknown')) : null;
            $conf  = $hasSuccess ? ($primaryProvider['confidence'] ?? null) : null;

            $candidates = [];
            foreach ([$museProv, $openAiProv] as $prov) {
                if (($prov['status'] ?? '') === 'success' && !empty($prov['alternatives'])) {
                    foreach ($prov['alternatives'] as $alt) {
                        $candidates[] = [
                            'kiribati_name'   => $alt['kiribati_name'] ?? null,
                            'english_name'    => $alt['common_name'] ?? ($alt['english_name'] ?? ''),
                            'scientific_name' => $alt['scientific_name'] ?? '',
                            'confidence'      => $alt['confidence'] ?? null,
                        ];
                    }
                }
            }

            $primaryVerification = $matchedDbSpecies->verification_status ?? 'unverified';
            $primaryRecordType   = $matchedDbSpecies->record_type ?? 'species';

            return response()->json([
                'status'            => $hasSuccess ? 'success' : 'unavailable',
                'comparison_status' => $comparisonResult['comparison_status'],
                'providers'         => $comparisonResult['providers'],
                'offline_catalogue' => $comparisonResult['offline_catalogue'] ?? null,
                'comparison'        => $comparisonResult['comparison'],
                'persisted_ids'     => $persistedIds,
                'image_url'         => $imagePath,
                'identification'    => [
                    'species_id'                  => $matchedDbSpecies ? $matchedDbSpecies->id : null,
                    'kiribati_name'               => $kName,
                    'english_name'                => $eName,
                    'scientific_name'             => $sName,
                    'scientific_name_as_provided' => $matchedDbSpecies->scientific_name_as_provided ?? $sName,
                    'verification_status'         => $primaryVerification,
                    'record_type'                 => $primaryRecordType,
                    'is_verified_record'          => $primaryVerification === 'verified',
                    'requires_human_confirmation' => true,
                    'source'                      => 'parallel_comparison',
                    'confidence'                  => $conf,
                    'confidence_percentage'       => $conf !== null ? (number_format($conf * 100, 1) . '%') : 'Not available',
                    'ciguatera_risk'              => $matchedDbSpecies->ciguatera_risk ?? 'unknown',
                    'ciguatera_warning'           => ($matchedDbSpecies && in_array($matchedDbSpecies->ciguatera_risk, ['high', 'extreme']))
                        ? 'High CFP risk reported in coastal reef zones.'
                        : null,
                    'visual_features'             => $primaryProvider['visible_features'] ?? [],
                    'model'                       => 'Parallel Comparison (' . ($primaryProvider['model'] ?? 'Meta SAM 3.1 + OpenAI') . ')',
                    'model_version'               => 'comparison-v1',
                    'analyzed_at'                 => now()->toIso8601String(),
                    'candidates'                  => array_slice($candidates, 0, 3),
                    'meta_model'                  => [
                        'status'    => $museProv['status'] ?? 'unconfigured',
                        'model'     => $museProv['model_identifier'] ?? ($museProv['model'] ?? 'sam-3.1'),
                        'reasoning' => $museProv['reasoning'] ?? null,
                    ],
                    'openai_model'                => [
                        'status' => $openAiProv['status'] ?? 'unconfigured',
                        'model'  => $openAiProv['model'] ?? 'gpt-4o',
                    ],
                    'message'                     => $hasSuccess ? null : 'Live AI identification is currently unavailable. Please try again when internet connectivity is restored or upload another photograph.',
                    'segmentation'                => $segmentation,
                    'segmentation_warning'        => $segmentationWarning,
                ],
                'segmentation_raw'  => $segmentationRaw,
                'disclaimer'        => $hasSuccess
                    ? 'AI identification is an advisory suggestion based on visible morphological characteristics. Photographs cannot detect marine toxins or ciguatera. Species identity and safety decisions require human expert confirmation.'
                    : 'Live AI identification is currently unavailable. Photographs cannot detect marine toxins or ciguatera. Species identity and safety decisions require human expert confirmation.',
            ]);
        }

        // ── 3-Stage AI Vision Pipeline: DB Photos -> Morphological Traits -> Web Vision ──
        $primary = null;
        $confidence = null;
        $matchedIndex = 0;
        $matchedModel = 'Kiribati Marine Biological Dataset (morphological knowledge base)';
        $matchedModelVersion = 'kb-v1';
        $matchedSource = 'knowledge_base';
        $dbSpecies = null;

        $pipelineInfo = [
            'stage_reached' => null,
            'stage_name'    => null,
            'stages'        => [
                'db_image_match'      => ['attempted' => false, 'status' => 'pending'],
                'morphological_match' => ['attempted' => false, 'status' => 'pending'],
                'web_vision'          => ['attempted' => false, 'status' => 'pending'],
                'openai_vision'       => ['attempted' => false, 'status' => 'pending'],
            ],
        ];

        $glimmerVisionInfo = [
            'configured' => $this->metaAiService->isConfigured(),
            'model'      => $this->metaAiService->getVisionModel(),
            'status'     => 'unconfigured',
            'latency_ms' => 0,
            'reasoning'  => null,
            'error'      => null,
            'pipeline'   => &$pipelineInfo,
        ];

        if ($this->metaAiService->isConfigured()) {
            $imageForVision = $dataUrl ?: $imagePath;
            if ($imageForVision) {
                // ── Stage 1 Preparation: Species with catalogued reference photos ──
                $dbSpeciesWithPhotos = Species::whereNotNull('photo_url')
                    ->where('photo_url', '!=', '')
                    ->get(['id', 'kiribati_name', 'english_name', 'scientific_name', 'ciguatera_risk', 'photo_url', 'visual_features'])
                    ->toArray();

                if (empty($dbSpeciesWithPhotos)) {
                    foreach (self::$speciesKnowledgeBase as $kbItem) {
                        if (!empty($kbItem['photo_url'])) {
                            $dbSpeciesWithPhotos[] = $kbItem;
                        }
                    }
                }

                // ── Stage 2 Preparation: Species with curated morphological traits ──
                $dbSpeciesWithTraits = Species::whereNotNull('visual_features')
                    ->get(['id', 'kiribati_name', 'english_name', 'scientific_name', 'ciguatera_risk', 'visual_features'])
                    ->toArray();

                $knownKiribatiNames = array_column($dbSpeciesWithTraits, 'kiribati_name');
                foreach (self::$speciesKnowledgeBase as $kbItem) {
                    if (!in_array($kbItem['kiribati_name'], $knownKiribatiNames)) {
                        $dbSpeciesWithTraits[] = $kbItem;
                    }
                }

                $primaryIdent = null;

                // ── STAGE 1: Match against backend fish species with pictures first ──
                $stage1 = $this->metaAiService->matchAgainstDbPhotos(
                    imageUrlOrDataUrl: $imageForVision,
                    speciesWithPhotos: $dbSpeciesWithPhotos,
                    samEvidence: $samResult,
                    hint: (string) $request->input('hint', '')
                );

                $pipelineInfo['stages']['db_image_match'] = [
                    'attempted'  => true,
                    'status'     => $stage1['status'],
                    'latency_ms' => $stage1['latency_ms'] ?? 0,
                    'confidence' => $stage1['identification']['confidence'] ?? null,
                    'reasoning'  => $stage1['identification']['reasoning'] ?? ($stage1['reasoning'] ?? null),
                    'error'      => $stage1['error'] ?? null,
                ];

                if ($stage1['status'] === 'completed' && !empty($stage1['identification'])) {
                    $pipelineInfo['stage_reached'] = 1;
                    $pipelineInfo['stage_name']    = 'db_image_match';
                    $matchedSource = 'db_image_match';
                    $primaryIdent  = $stage1['identification'];
                    $glimmerVisionInfo['status']     = 'completed';
                    $glimmerVisionInfo['reasoning']  = $primaryIdent['reasoning'] ?? null;
                    $glimmerVisionInfo['latency_ms'] = $stage1['latency_ms'] ?? 0;
                }

                // ── STAGE 2: If Stage 1 did not match, match by Morphological Traits ──
                if (!$primaryIdent && in_array($stage1['status'], ['no_match', 'partial'])) {
                    $stage2 = $this->metaAiService->matchByMorphologicalTraits(
                        imageUrlOrDataUrl: $imageForVision,
                        speciesWithTraits: $dbSpeciesWithTraits,
                        samEvidence: $samResult,
                        hint: (string) $request->input('hint', '')
                    );

                    $pipelineInfo['stages']['morphological_match'] = [
                        'attempted'  => true,
                        'status'     => $stage2['status'],
                        'latency_ms' => $stage2['latency_ms'] ?? 0,
                        'confidence' => $stage2['identification']['confidence'] ?? null,
                        'reasoning'  => $stage2['identification']['reasoning'] ?? ($stage2['reasoning'] ?? null),
                        'error'      => $stage2['error'] ?? null,
                    ];

                    if ($stage2['status'] === 'completed' && !empty($stage2['identification'])) {
                        $pipelineInfo['stage_reached'] = 2;
                        $pipelineInfo['stage_name']    = 'morphological_match';
                        $matchedSource = 'morphological_match';
                        $primaryIdent  = $stage2['identification'];
                        $glimmerVisionInfo['status']     = 'completed';
                        $glimmerVisionInfo['reasoning']  = $primaryIdent['reasoning'] ?? null;
                        $glimmerVisionInfo['latency_ms'] = ($glimmerVisionInfo['latency_ms'] ?? 0) + ($stage2['latency_ms'] ?? 0);
                    }
                }

                // ── STAGE 3: If nothing matched, search broad Web/Global taxonomy vision ──
                if (!$primaryIdent && in_array($stage1['status'], ['no_match', 'partial']) && in_array($pipelineInfo['stages']['morphological_match']['status'] ?? 'no_match', ['no_match', 'partial'])) {
                    $stage3 = $this->metaAiService->identifyFromWeb(
                        imageUrlOrDataUrl: $imageForVision,
                        samEvidence: $samResult,
                        hint: (string) $request->input('hint', '')
                    );

                    $pipelineInfo['stages']['web_vision'] = [
                        'attempted'  => true,
                        'status'     => $stage3['status'],
                        'latency_ms' => $stage3['latency_ms'] ?? 0,
                        'confidence' => $stage3['identification']['confidence'] ?? null,
                        'reasoning'  => $stage3['identification']['reasoning'] ?? null,
                        'error'      => $stage3['error'] ?? null,
                    ];

                    if ($stage3['status'] === 'completed' && !empty($stage3['identification'])) {
                        $pipelineInfo['stage_reached'] = 3;
                        $pipelineInfo['stage_name']    = 'web_vision';
                        $matchedSource = 'web_vision';
                        $primaryIdent  = $stage3['identification'];
                        $glimmerVisionInfo['status']     = 'completed';
                        $glimmerVisionInfo['reasoning']  = $primaryIdent['reasoning'] ?? null;
                        $glimmerVisionInfo['latency_ms'] = ($glimmerVisionInfo['latency_ms'] ?? 0) + ($stage3['latency_ms'] ?? 0);
                    }
                }

                if ($primaryIdent) {
                    // Match detected species with Laravel database species
                    $dbMatch = Species::where('kiribati_name', 'like', "%{$primaryIdent['kiribati_name']}%")
                        ->orWhere('english_name', 'like', "%{$primaryIdent['english_name']}%")
                        ->orWhere('scientific_name', 'like', "%{$primaryIdent['scientific_name']}%")
                        ->first();

                    $primary = [
                        'kiribati_name'    => $dbMatch ? $dbMatch->kiribati_name : $primaryIdent['kiribati_name'],
                        'english_name'     => $dbMatch ? $dbMatch->english_name : $primaryIdent['english_name'],
                        'scientific_name'  => $dbMatch ? $dbMatch->scientific_name : $primaryIdent['scientific_name'],
                        'ciguatera_risk'   => $dbMatch->ciguatera_risk ?? ($primaryIdent['ciguatera_risk'] ?? 'low'),
                        'confidence_range' => [$primaryIdent['confidence'], $primaryIdent['confidence']],
                        'photo_url'        => $dbMatch->photo_url ?? $imagePath,
                        'visual_features'  => !empty($primaryIdent['visual_features'])
                            ? $primaryIdent['visual_features']
                            : ($dbMatch->visual_features ?? []),
                        'ciguatera_warning'=> ($dbMatch && in_array($dbMatch->ciguatera_risk, ['high', 'extreme']))
                            ? 'High CFP risk reported in coastal reef zones.'
                            : null,
                    ];
                    $confidence = $primaryIdent['confidence'];
                    $matchedModel = 'Meta Muse Multimodal Vision (' . $this->metaAiService->getVisionModel() . ')';
                    $matchedModelVersion = 'muse-vision-v1';
                    $dbSpecies = $dbMatch;
                } else {
                    $glimmerVisionInfo['status'] = in_array('failed', [
                        $pipelineInfo['stages']['db_image_match']['status'],
                        $pipelineInfo['stages']['morphological_match']['status'],
                        $pipelineInfo['stages']['web_vision']['status'],
                    ]) ? 'failed' : 'no_match';
                    $glimmerVisionInfo['error'] = $stage1['error'] ?? ($stage2['error'] ?? ($stage3['error'] ?? null));
                }
            }
        }

        // Fallback: If Muse Glimmer unconfigured, failed, or returned null, use knowledge base matching
        if (!$primary) {
            $hint = strtolower($request->input('hint', ''));

            if ($hint) {
                foreach (self::$speciesKnowledgeBase as $idx => $spec) {
                    if (
                        str_contains(strtolower($spec['kiribati_name']), $hint) ||
                        str_contains(strtolower($spec['english_name']), $hint) ||
                        str_contains(strtolower($spec['scientific_name']), $hint)
                    ) {
                        $matchedIndex = $idx;
                        $primary = $spec;
                        break;
                    }
                }

                if (!$primary) {
                    $dbMatch = Species::where('kiribati_name', 'like', "%{$hint}%")
                        ->orWhere('english_name', 'like', "%{$hint}%")
                        ->orWhere('scientific_name', 'like', "%{$hint}%")
                        ->first();
                    if ($dbMatch) {
                        $primary = [
                            'kiribati_name' => $dbMatch->kiribati_name,
                            'english_name' => $dbMatch->english_name,
                            'scientific_name' => $dbMatch->scientific_name,
                            'ciguatera_risk' => $dbMatch->ciguatera_risk ?? 'low',
                            'confidence_range' => [0.88, 0.94],
                            'photo_url' => $dbMatch->photo_url,
                            'visual_features' => !empty($dbMatch->visual_features) ? $dbMatch->visual_features : [
                                "Morphological traits matching {$dbMatch->kiribati_name} ({$dbMatch->english_name})",
                                "Taxonomic classification: {$dbMatch->scientific_name}",
                            ],
                            'ciguatera_warning' => ($dbMatch->ciguatera_risk === 'high' || $dbMatch->ciguatera_risk === 'extreme')
                                ? 'High CFP risk reported in coastal reef zones.'
                                : null,
                        ];
                        $dbSpecies = $dbMatch;
                    }
                }
            }

            // If no primary match from hint, check if photo matches a known species photo_url
            if (!$primary && $imagePath) {
                foreach (self::$speciesKnowledgeBase as $idx => $spec) {
                    if (!empty($spec['photo_url']) && str_contains($imagePath, $spec['photo_url'])) {
                        $matchedIndex = $idx;
                        $primary = $spec;
                        break;
                    }
                }

                if (!$primary) {
                    $dbMatch = Species::whereNotNull('photo_url')
                        ->where('photo_url', $imagePath)
                        ->first();
                    if ($dbMatch) {
                        $primary = [
                            'kiribati_name' => $dbMatch->kiribati_name,
                            'english_name' => $dbMatch->english_name,
                            'scientific_name' => $dbMatch->scientific_name,
                            'ciguatera_risk' => $dbMatch->ciguatera_risk ?? 'low',
                            'confidence_range' => [0.88, 0.94],
                            'photo_url' => $dbMatch->photo_url,
                            'visual_features' => !empty($dbMatch->visual_features) ? $dbMatch->visual_features : [
                                "Morphological traits matching {$dbMatch->kiribati_name} ({$dbMatch->english_name})",
                                "Taxonomic classification: {$dbMatch->scientific_name}",
                            ],
                            'ciguatera_warning' => ($dbMatch->ciguatera_risk === 'high' || $dbMatch->ciguatera_risk === 'extreme')
                                ? 'High CFP risk reported in coastal reef zones.'
                                : null,
                        ];
                        $dbSpecies = $dbMatch;
                    }
                }
            }

            // ── STAGE 4: OpenAI Vision API — Ultimate Fallback ──────────────────────
            // Triggered only when all 3 Meta Muse Glimmer stages AND local KB/photo_url
            // matching have produced no result AND OPENAI_API_KEY is configured.
            if (!$primary && $this->openAiService->isConfigured()) {
                $imageForOpenAi = $dataUrl ?: $imagePath;
                if ($imageForOpenAi) {
                    // Collect reference species for prompt grounding
                    $dbReferenceSpecies = Species::whereIn('verification_status', ['verified', 'needs_review'])
                        ->whereIn('record_type', ['species'])
                        ->get(['kiribati_name', 'english_name', 'scientific_name'])
                        ->toArray();
                    if (empty($dbReferenceSpecies)) {
                        $dbReferenceSpecies = array_map(fn ($s) => [
                            'kiribati_name'  => $s['kiribati_name'],
                            'english_name'   => $s['english_name'],
                            'scientific_name'=> $s['scientific_name'],
                        ], self::$speciesKnowledgeBase);
                    }

                    $stage4 = $this->openAiService->identifyFishVision(
                        imageUrlOrDataUrl: $imageForOpenAi,
                        referenceSpecies: $dbReferenceSpecies,
                        hint: (string) $request->input('hint', '')
                    );

                    $pipelineInfo['stages']['openai_vision'] = [
                        'attempted'  => true,
                        'status'     => $stage4['status'],
                        'latency_ms' => $stage4['latency_ms'] ?? 0,
                        'confidence' => $stage4['identification']['confidence'] ?? null,
                        'reasoning'  => $stage4['identification']['reasoning'] ?? ($stage4['error'] ?? null),
                        'error'      => $stage4['error'] ?? null,
                    ];

                    if ($stage4['status'] === 'completed' && !empty($stage4['identification'])) {
                        $oaIdent = $stage4['identification'];

                        // Try to match to a DB species record for canonical names
                        $oaDbMatch = Species::where('kiribati_name', 'like', "%{$oaIdent['kiribati_name']}%")
                            ->orWhere('english_name', 'like', "%{$oaIdent['english_name']}%")
                            ->orWhere('scientific_name', 'like', "%{$oaIdent['scientific_name']}%")
                            ->first();

                        $primary = [
                            'kiribati_name'   => $oaDbMatch ? $oaDbMatch->kiribati_name : $oaIdent['kiribati_name'],
                            'english_name'    => $oaDbMatch ? $oaDbMatch->english_name  : $oaIdent['english_name'],
                            'scientific_name' => $oaDbMatch ? $oaDbMatch->scientific_name : $oaIdent['scientific_name'],
                            'ciguatera_risk'  => $oaDbMatch->ciguatera_risk ?? ($oaIdent['ciguatera_risk'] ?? 'low'),
                            'confidence_range'=> [$oaIdent['confidence'], $oaIdent['confidence']],
                            'photo_url'       => $oaDbMatch->photo_url ?? $imagePath,
                            'visual_features' => !empty($oaIdent['visual_features'])
                                ? $oaIdent['visual_features']
                                : ($oaDbMatch->visual_features ?? []),
                            'ciguatera_warning' => ($oaDbMatch && in_array($oaDbMatch->ciguatera_risk, ['high', 'extreme']))
                                ? 'High CFP risk reported in coastal reef zones.'
                                : null,
                        ];
                        $confidence          = $oaIdent['confidence'];
                        $matchedSource       = 'openai_vision';
                        $matchedModel        = 'OpenAI Vision (' . $this->openAiService->getModel() . ')';
                        $matchedModelVersion = 'openai-vision-v1';
                        $dbSpecies           = $oaDbMatch ?? null;
                        $pipelineInfo['stage_reached'] = 4;
                        $pipelineInfo['stage_name']    = 'openai_vision';
                    }
                }
            }

            if (!$primary) {
                $confidence          = null;
                $matchedSource       = 'insufficient_evidence';
                $matchedModel        = 'None (no provider available or matched)';
                $matchedModelVersion = null;
                $pipelineInfo['stage_reached'] = 0;
                $pipelineInfo['stage_name']    = 'insufficient_evidence';
            } else {
                // Only reassign source/model to KB fallback if Stage 4 (OpenAI) also did not succeed
                if ($matchedSource !== 'openai_vision') {
                    $confidence = null; // Grounded: non-vision fallback must not fabricate confidence scores

                    if (!$dbSpecies) {
                        $dbSpecies = Species::where('kiribati_name', $primary['kiribati_name'])
                            ->orWhere('english_name', $primary['english_name'])
                            ->first();
                    }
                    $matchedSource       = $dbSpecies ? 'reference_database' : 'knowledge_base';
                    $matchedModel        = 'Kiribati Marine Biological Dataset (morphological knowledge base)';
                    $matchedModelVersion = 'kb-v1';
                    $pipelineInfo['stage_reached'] = 0;
                    $pipelineInfo['stage_name']    = 'knowledge_base_fallback';
                }
            }
        }


        // Find candidate matches from knowledge base
        $candidates = [];
        if ($primary) {
            foreach (self::$speciesKnowledgeBase as $idx => $spec) {
                if ($spec['kiribati_name'] === $primary['kiribati_name']) continue;
                $candidates[] = [
                    'kiribati_name'  => $spec['kiribati_name'],
                    'english_name'   => $spec['english_name'],
                    'scientific_name'=> $spec['scientific_name'],
                    'confidence'     => $confidence !== null ? round($confidence * 0.72, 2) : null,
                ];
                if (count($candidates) >= 3) break;
            }
        }

        if ($primary && !$dbSpecies) {
            $dbSpecies = Species::where('kiribati_name', $primary['kiribati_name'])
                ->orWhere('english_name', $primary['english_name'])
                ->first();
        }

        // ── Muse Wonder 1.0 fisheries advisory (text-only, grounded in KB) ──
        $museAdvisory = null;
        $museModelInfo = [
            'configured'     => $this->metaAiService->isConfigured(),
            'model'          => $this->metaAiService->getModel(),
            'mode'           => 'text_fisheries_assistant',
            'advisory_notes' => null,
            'latency_ms'     => 0,
            'status'         => 'unconfigured',
            'glimmer_vision' => $glimmerVisionInfo,
        ];

        if ($primary && $this->metaAiService->isConfigured()) {
            $museResult = $this->metaAiService->queryFisheriesAssistant(
                kbKiribatiName:   $primary['kiribati_name'],
                kbEnglishName:    $primary['english_name'],
                kbScientificName: $primary['scientific_name'],
                samEvidence:      $samResult,
                hint:             $request->input('hint', ''),
            );
            $museAdvisory = $museResult['advisory'];
            $museModelInfo = array_merge($museModelInfo, [
                'advisory_notes' => $museAdvisory,
                'latency_ms'     => $museResult['latency_ms'],
                'status'         => $museResult['status'],
                'error'          => $museResult['error'] ?? null,
            ]);
        }

        // Reference-database candidates (P1-06)
        $rawHint = trim((string) $request->input('hint', ''));
        $refQuery = Species::query();
        if ($rawHint !== '') {
            $like = '%' . $rawHint . '%';
            $refQuery->where(function ($q) use ($like) {
                $q->where('kiribati_name', 'like', $like)
                    ->orWhere('english_name', 'like', $like)
                    ->orWhere('scientific_name', 'like', $like)
                    ->orWhere('scientific_name_as_provided', 'like', $like);
            });
        } elseif ($dbSpecies) {
            $refQuery->where(function ($q) use ($dbSpecies, $primary) {
                $q->where('id', $dbSpecies->id);
                if ($primary) {
                    $q->orWhere('kiribati_name', $primary['kiribati_name'])
                      ->orWhere('scientific_name', $primary['scientific_name']);
                }
            });
        } elseif ($primary) {
            $refQuery->where('kiribati_name', $primary['kiribati_name']);
        } else {
            $refQuery->whereRaw('1 = 0');
        }
        $referenceCandidates = $refQuery
            ->orderByRaw("CASE WHEN verification_status = 'verified' THEN 0 ELSE 1 END")
            ->orderBy('kiribati_name')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'species_id' => $s->id,
                'kiribati_name' => $s->kiribati_name,
                'english_name' => $s->english_name,
                'scientific_name' => $s->scientific_name,
                'scientific_name_as_provided' => $s->scientific_name_as_provided,
                'ciguatera_risk' => $s->ciguatera_risk,
                'verification_status' => $s->verification_status ?? 'needs_review',
                'record_type' => $s->record_type ?? 'species',
                'is_verified_record' => ($s->verification_status ?? null) === 'verified',
                'source' => 'reference_database',
                'requires_human_confirmation' => true,
            ])
            ->values()
            ->all();

        $primaryVerification = $dbSpecies->verification_status ?? ($primary ? 'needs_review' : 'unverified');
        $primaryRecordType = $dbSpecies->record_type ?? 'species';

        $museSuccess = in_array($matchedSource, ['db_image_match', 'morphological_match', 'web_vision']) || $glimmerVisionInfo['status'] === 'completed';
        $openAiSuccess = ($matchedSource === 'openai_vision') || ($pipelineInfo['stages']['openai_vision']['status'] ?? '') === 'completed';
        $hasAnySuccess = $museSuccess || $openAiSuccess || ($primary !== null);

        $museStatus = $museSuccess ? 'success' : ($glimmerVisionInfo['status'] ?? 'unconfigured');
        if (in_array($museStatus, ['failed', 'no_match']) && !$this->metaAiService->isConfigured()) {
            $museStatus = 'unconfigured';
        }

        $openAiStatus = $openAiSuccess ? 'success' : ($pipelineInfo['stages']['openai_vision']['status'] ?? 'unconfigured');
        if (in_array($openAiStatus, ['failed', 'no_match']) && !$this->openAiService->isConfigured()) {
            $openAiStatus = 'unconfigured';
        }

        $comparisonStatus = ($museSuccess && $openAiSuccess)
            ? 'agreement'
            : (($museSuccess || $openAiSuccess)
                ? 'single_provider_result'
                : ($primary ? 'fallback' : 'both_failed'));

        return response()->json([
            'status'            => $hasAnySuccess ? 'success' : 'unavailable',
            'comparison_status' => $comparisonStatus,
            'providers' => [
                'muse_glimmer' => [
                    'status'            => $museStatus,
                    'model'             => $glimmerVisionInfo['model'] ?? $this->metaAiService->getVisionModel(),
                    'suggested_species' => $museSuccess && $primary ? [
                        'scientific_name' => $primary['scientific_name'] ?? null,
                        'common_name'     => $primary['english_name'] ?? null,
                        'kiribati_name'   => $primary['kiribati_name'] ?? null,
                    ] : null,
                    'confidence'        => $museSuccess ? $confidence : null,
                ],
                'openai' => [
                    'status'            => $openAiStatus,
                    'model'             => $this->openAiService->getModel(),
                    'suggested_species' => $openAiSuccess && $primary ? [
                        'scientific_name' => $primary['scientific_name'] ?? null,
                        'common_name'     => $primary['english_name'] ?? null,
                        'kiribati_name'   => $primary['kiribati_name'] ?? null,
                    ] : null,
                    'confidence'        => $openAiSuccess ? $confidence : null,
                ],
            ],
            'comparison' => [
                'scientific_name_match'        => $museSuccess && $openAiSuccess,
                'catalogue_match'              => (bool) $dbSpecies,
                'human_confirmation_required' => true,
            ],
            'image_url' => $imagePath,
            'identification' => $primary ? [
                'species_id'       => $dbSpecies ? $dbSpecies->id : null,
                'kiribati_name'    => $primary['kiribati_name'],
                'english_name'     => $primary['english_name'],
                'scientific_name'  => $primary['scientific_name'],
                'scientific_name_as_provided' => $dbSpecies->scientific_name_as_provided ?? $primary['scientific_name'],
                'verification_status' => $primaryVerification,
                'record_type'      => $primaryRecordType,
                'is_verified_record' => $primaryVerification === 'verified',
                'requires_human_confirmation' => true,
                'source'           => $matchedSource,
                'pipeline_stage'   => $pipelineInfo['stage_name'],
                'pipeline'         => $pipelineInfo,
                'reference_candidates' => $referenceCandidates,
                'confidence'       => $confidence,
                'confidence_percentage' => $confidence !== null ? (number_format($confidence * 100, 1) . '%') : 'Not available',
                'ciguatera_risk'   => $primary['ciguatera_risk'] ?? 'low',
                'ciguatera_warning'=> $primary['ciguatera_warning'] ?? null,
                'visual_features'  => $primary['visual_features'] ?? [],
                'model'            => $matchedModel,
                'model_version'    => $matchedModelVersion,
                'analyzed_at'      => now()->toIso8601String(),
                'candidates'       => $candidates,
                'meta_model'       => $museModelInfo,
                'openai_model'     => [
                    'configured' => $this->openAiService->isConfigured(),
                    'model'      => $this->openAiService->getModel(),
                    'status'     => $pipelineInfo['stages']['openai_vision']['status'] ?? 'pending',
                    'attempted'  => $pipelineInfo['stages']['openai_vision']['attempted'] ?? false,
                    'reasoning'  => $pipelineInfo['stages']['openai_vision']['reasoning'] ?? null,
                    'latency_ms' => $pipelineInfo['stages']['openai_vision']['latency_ms'] ?? 0,
                ],
                'segmentation'     => $segmentation,
                'segmentation_warning' => $segmentationWarning,
            ] : [
                'species_id'       => null,
                'kiribati_name'    => null,
                'english_name'     => null,
                'scientific_name'  => null,
                'scientific_name_as_provided' => null,
                'verification_status' => 'insufficient_evidence',
                'record_type'      => 'species',
                'is_verified_record' => false,
                'requires_human_confirmation' => true,
                'source'           => 'insufficient_evidence',
                'pipeline_stage'   => 'insufficient_evidence',
                'pipeline'         => $pipelineInfo,
                'reference_candidates' => $referenceCandidates,
                'confidence'       => null,
                'confidence_percentage' => 'Not available',
                'ciguatera_risk'   => 'unknown',
                'ciguatera_warning'=> null,
                'visual_features'  => [],
                'model'            => $matchedModel,
                'model_version'    => $matchedModelVersion,
                'analyzed_at'      => now()->toIso8601String(),
                'candidates'       => [],
                'meta_model'       => $museModelInfo,
                'openai_model'     => [
                    'configured' => $this->openAiService->isConfigured(),
                    'model'      => $this->openAiService->getModel(),
                    'status'     => $pipelineInfo['stages']['openai_vision']['status'] ?? 'pending',
                    'attempted'  => $pipelineInfo['stages']['openai_vision']['attempted'] ?? false,
                    'reasoning'  => $pipelineInfo['stages']['openai_vision']['reasoning'] ?? null,
                    'latency_ms' => $pipelineInfo['stages']['openai_vision']['latency_ms'] ?? 0,
                ],
                'segmentation'     => $segmentation,
                'segmentation_warning' => $segmentationWarning,
                'message'          => 'No live AI identification was performed. Species identity requires human expert confirmation.',
            ],
            'identification_pipeline' => $pipelineInfo,
            // Verbatim SAM provider output (null when unconfigured).
            'segmentation_raw' => $segmentationRaw,
            'disclaimer' => $hasAnySuccess
                ? 'AI identification is an advisory suggestion based on visible morphological characteristics. Photographs cannot detect marine toxins or ciguatera. Species identity and safety decisions require human expert confirmation.'
                : 'Live AI identification is currently unavailable. Photographs cannot detect marine toxins or ciguatera. Species identity and safety decisions require human expert confirmation.',
        ]);
    }

    /**
     * Dedicated parallel provider comparison endpoint.
     * POST /api/ai/compare-providers
     */
    public function compareProviders(Request $request)
    {
        $request->merge(['compare' => true]);
        return $this->identify($request);
    }



    /**
     * Meta Model API Health Check & Connectivity Probe Endpoint
     * GET /api/ai/meta-health or POST /api/ai/meta-health
     * Restricted to authorized administrators and compliance reviewers.
     * Note: API key is never returned in response.
     */
    public function metaHealth(Request $request)
    {
        $health = $this->metaAiService->testConnection();
        $health['sam'] = [
            'configured' => $this->samService->isConfigured(),
            'model' => $this->samService->getModel(),
        ];
        // Image modality is reported, not probed, here: probing would
        // be a billable call. muse-wonder-1.0 is known text-only
        // (ADR-016); sam-3.1 is the image model. Use
        // MetaAiService::investigateImageModality() explicitly (with
        // Http::fake in tests) to probe.
        $health['image_modality'] = [
            'muse_glimmer_vision_model' => $this->metaAiService->getVisionModel(),
            'muse_glimmer_multimodal_vision' => true,
            'muse_glimmer_primary_classifier' => true,
            'muse_wonder_model' => $this->metaAiService->getModel(),
            'muse_wonder_supports_image' => null,
            'muse_wonder_tested' => false,
            'muse_wonder_known_text_only' => true,
            'sam_image_model' => $this->samService->getModel(),
            'sam_supports_image' => true,
            'recommendation' => 'Use muse-spark-1.3 for primary multimodal fish species visual identification from photographs, sam-3.1 for object segmentation evidence, and muse-wonder-1.0 for biological advisory text reasoning.',
        ];
        $health['openai_vision'] = [
            'configured'   => $this->openAiService->isConfigured(),
            'model'        => $this->openAiService->getModel(),
            'pipeline_stage' => 4,
            'description'  => 'Ultimate fallback vision classifier — runs after all 3 Meta Muse Glimmer stages and local species-matching have finished without a result.',
        ];
        $httpStatus = ($health['status'] === 'degraded') ? 503 : 200;

        return response()->json($health, $httpStatus);
    }

    /**
     * Resolve image bytes to a data URL for SAM (which requires data URLs —
     * our device uploads are not publicly reachable by the provider).
     * Returns null when bytes cannot be obtained (never throws).
     */
    protected function resolveImageDataUrl(?string $imageDataUrl, ?string $uploadedLocalPath, ?string $imagePath): ?string
    {
        try {
            if ($imageDataUrl && str_starts_with($imageDataUrl, 'data:image/')) {
                return $imageDataUrl;
            }

            $localPath = $uploadedLocalPath;
            if ($localPath === null && $imagePath && str_contains($imagePath, 'uploads/catches/')) {
                $localPath = public_path('uploads/catches/' . basename($imagePath));
            }
            if ($localPath !== null && is_file($localPath)) {
                $bytes = file_get_contents($localPath);
                if ($bytes !== false && strlen($bytes) <= 10485760) {
                    $mime = mime_content_type($localPath) ?: 'image/jpeg';
                    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
                }
                return null;
            }

            if ($imagePath && str_starts_with($imagePath, 'http')) {
                $res = Http::withOptions($this->getCurlOptions($imagePath))->timeout(10)->get($imagePath);
                if ($res->successful()) {
                    $bytes = $res->body();
                    $contentType = (string) $res->header('Content-Type', 'image/jpeg');
                    if ($bytes !== '' && strlen($bytes) <= 10485760 && str_contains($contentType, 'image')) {
                        return 'data:' . trim(explode(';', $contentType)[0]) . ';base64,' . base64_encode($bytes);
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
