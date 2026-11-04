<?php

namespace App\Services;

use App\Models\FishIdentificationProvider;
use App\Models\Species;
use Illuminate\Support\Facades\Log;

/**
 * FishIdentificationComparisonService
 *
 * Coordinates independent evaluation of fish images across multiple AI vision providers:
 *  1. Meta Muse Glimmer (MetaAiService)
 *  2. OpenAI Vision (OpenAiFishVisionService)
 *
 * Normalizes results into a standardized schema, matches scientific names against the
 * Kiribati reference species catalogue without inventing database records, and evaluates
 * comparison status (agreement, disagreement, single_provider_result, both_failed, insufficient_evidence).
 *
 * Scientific & Safety Compliance:
 * - AI identification is advisory only; human confirmation is always required.
 * - Confidences are never averaged.
 * - Agreement is never treated as verified ground truth.
 */
class FishIdentificationComparisonService
{
    protected MetaAiService $metaAiService;
    protected OpenAiFishVisionService $openAiFishVisionService;

    public function __construct(
        MetaAiService $metaAiService,
        OpenAiFishVisionService $openAiFishVisionService
    ) {
        $this->metaAiService            = $metaAiService;
        $this->openAiFishVisionService  = $openAiFishVisionService;
    }

    /**
     * Compare fish photograph suggestions from Meta Muse Glimmer and OpenAI Vision.
     *
     * @param string $imageUrlOrDataUrl Image URL or Base64 data URL
     * @param array  $referenceSpecies  Kiribati reference catalogue records
     * @param string $hint              Optional fisher/observer hint
     * @param array  $samEvidence       SAM 3.1 segmentation evidence
     * @param array  $options           Optional overrides
     * @return array Standardized comparison structure
     */
    public function compare(
        string $imageUrlOrDataUrl,
        array  $referenceSpecies = [],
        string $hint = '',
        array  $samEvidence = [],
        array  $options = []
    ): array {
        // If referenceSpecies empty, populate from database or knowledge base
        if (empty($referenceSpecies)) {
            $referenceSpecies = Species::whereIn('record_type', ['species'])
                ->get(['id', 'kiribati_name', 'english_name', 'scientific_name', 'ciguatera_risk'])
                ->toArray();
        }

        // ── 1. Call Meta Muse Glimmer independently ──
        $museRaw = null;
        $museError = null;
        $museDurationMs = 0;
        $museStart = hrtime(true);

        try {
            $museRaw = $this->metaAiService->identifyFishVision(
                $imageUrlOrDataUrl,
                $referenceSpecies,
                $samEvidence,
                $hint,
                $options
            );
            $museDurationMs = (int) round((hrtime(true) - $museStart) / 1_000_000);
        } catch (\Throwable $e) {
            $museDurationMs = (int) round((hrtime(true) - $museStart) / 1_000_000);
            $museError = $e->getMessage();
            Log::warning('ComparisonService: Muse Glimmer call threw exception', ['error' => $museError]);
        }

        // ── 2. Call OpenAI Vision independently ──
        $openAiRaw = null;
        $openAiError = null;
        $openAiDurationMs = 0;
        $openAiStart = hrtime(true);

        try {
            $openAiRaw = $this->openAiFishVisionService->identifyFishVision(
                $imageUrlOrDataUrl,
                $referenceSpecies,
                $hint,
                $options
            );
            $openAiDurationMs = (int) round((hrtime(true) - $openAiStart) / 1_000_000);
        } catch (\Throwable $e) {
            $openAiDurationMs = (int) round((hrtime(true) - $openAiStart) / 1_000_000);
            $openAiError = $e->getMessage();
            Log::warning('ComparisonService: OpenAI Vision call threw exception', ['error' => $openAiError]);
        }

        // ── 3. Normalize Provider Responses Honestly ──
        $museNormalized = $this->normalizeMuseResponse($museRaw, $museError, $museDurationMs, $samEvidence);
        $openAiNormalized = $this->normalizeOpenAiResponse($openAiRaw, $openAiError, $openAiDurationMs);

        // ── 4. Match Scientific Names Against Species Catalogue (Successes Only) ──
        $museNormalized   = $this->attachCatalogueMatch($museNormalized);
        $openAiNormalized = $this->attachCatalogueMatch($openAiNormalized);

        // ── 5. Determine Comparison Status ──
        $comparison = $this->evaluateComparison($museNormalized, $openAiNormalized);

        // ── 6. Generate Offline Catalogue Assistance (Reference Only, Never Fake AI) ──
        $offlineCatalogue = $this->generateOfflineCatalogueReference($hint, $referenceSpecies);

        return [
            'comparison_status' => $comparison['status'],
            'providers' => [
                'muse_glimmer' => $museNormalized,
                'openai'       => $openAiNormalized,
            ],
            'offline_catalogue' => $offlineCatalogue,
            'comparison'        => $comparison['details'],
        ];
    }

    /**
     * Persist provider results to the fish_identification_providers database table.
     *
     * @param array    $comparisonResult Return value from compare()
     * @param int|null $catchId          Optional associated catch record ID
     * @return array Array of created model IDs ['muse_glimmer' => id, 'openai' => id]
     */
    public function persistComparison(array $comparisonResult, ?int $catchId = null): array
    {
        $createdIds = [];
        $providers = $comparisonResult['providers'] ?? [];

        foreach ($providers as $providerKey => $data) {
            try {
                $status = $data['status'] ?? 'failed';
                $suggested = $data['suggested_species'] ?? null;
                $suggestedText = null;
                if (!empty($suggested)) {
                    $suggestedText = !empty($suggested['kiribati_name'])
                        ? "{$suggested['kiribati_name']} (" . ($suggested['common_name'] ?? 'Unknown') . ")"
                        : ($suggested['common_name'] ?? ($data['scientific_name'] ?? null));
                }

                $record = FishIdentificationProvider::create([
                    'catch_id'            => $catchId,
                    'provider_name'       => $providerKey,
                    'model_identifier'    => $data['model_identifier'] ?? ($data['model'] ?? 'unknown'),
                    'suggested_species'   => $suggestedText,
                    'scientific_name'     => $data['scientific_name'] ?? null,
                    'confidence'          => $data['confidence'] ?? null,
                    'visible_traits'      => $data['visible_features'] ?? [],
                    'explanation'         => $data['reasoning'] ?? null,
                    'alternative_species' => $data['alternatives'] ?? [],
                    'raw_response'        => is_array($data['raw_response'] ?? null)
                        ? json_encode($data['raw_response'])
                        : ($data['raw_response'] ?? null),
                    'duration_ms'         => $data['duration_ms'] ?? 0,
                    'error_status'        => $status === 'success' ? 'success' : ($data['error'] ?? $status),
                ]);

                $createdIds[$providerKey] = $record->id;
            } catch (\Throwable $e) {
                Log::error('ComparisonService: Failed to persist provider record', [
                    'provider' => $providerKey,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $createdIds;
    }

    /**
     * Normalize Meta Muse vision response.
     * SAM evidence stays separate (segmentation only, never species or confidence).
     */
    public function normalizeMuseResponse(?array $raw, ?string $exceptionError = null, int $durationMs = 0, array $samEvidence = []): array
    {
        $model = $this->metaAiService->getVisionModel();
        $rawStatus = $raw['status'] ?? '';
        $rawError = $raw['error'] ?? null;

        if ($exceptionError !== null) {
            $isTimeout = str_contains(strtolower($exceptionError), 'timeout')
                || str_contains(strtolower($exceptionError), 'timed out');
            $isUnavailable = str_contains($exceptionError, 'Could not resolve host')
                || str_contains($exceptionError, 'cURL error 6')
                || str_contains($exceptionError, 'Failed to connect')
                || str_contains($exceptionError, 'Connection refused')
                || str_contains($exceptionError, 'Network is unreachable');

            $status = $isTimeout ? 'timeout' : ($isUnavailable ? 'unavailable' : 'failed');

            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $model,
                'status'              => $status,
                'model'               => $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed. External provider was unavailable or failed.',
                'alternatives'        => [],
                'duration_ms'         => $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => null,
                'error'               => $exceptionError,
            ];
        }

        if (!$raw || ($raw['status'] ?? '') === 'unconfigured') {
            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'unavailable',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => false,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed. Meta Muse Glimmer is unconfigured.',
                'alternatives'        => [],
                'duration_ms'         => $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => null,
                'error'               => $raw['error'] ?? 'MODEL_API_KEY is not configured in backend environment.',
            ];
        }

        $rawStatus = $raw['status'] ?? '';
        $rawError = $raw['error'] ?? null;
        $isTimeout = ($rawStatus === 'timeout')
            || (is_string($rawError) && (str_contains(strtolower($rawError), 'timeout') || str_contains(strtolower($rawError), 'timed out')));
        $isUnavailable = is_string($rawError) && (
            str_contains($rawError, 'Could not resolve host') ||
            str_contains($rawError, 'cURL error 6') ||
            str_contains($rawError, 'Failed to connect') ||
            str_contains($rawError, 'Connection refused')
        );

        if ($isTimeout) {
            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'timeout',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (request timed out).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_text'] ?? null,
                'error'               => $rawError ?? 'Meta Muse Glimmer request timed out.',
            ];
        }

        if ($isUnavailable) {
            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'unavailable',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed. External API endpoint unreachable.',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_text'] ?? null,
                'error'               => $rawError,
            ];
        }

        if ($rawStatus === 'rate_limited') {
            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'rate_limited',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (Meta API rate limit reached).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_text'] ?? null,
                'error'               => $rawError ?? 'Meta API rate limited.',
            ];
        }

        if ($rawStatus === 'unauthorized') {
            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'unauthorized',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (Meta API authentication failed).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_text'] ?? null,
                'error'               => $rawError ?? 'Meta API authentication failed.',
            ];
        }

        if ($rawStatus === 'invalid_payload') {
            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'invalid_payload',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (Invalid payload sent to Meta API).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_text'] ?? null,
                'error'               => $rawError ?? 'Meta API invalid payload.',
            ];
        }

        if ($rawStatus === 'model_unavailable') {
            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'model_unavailable',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (Meta model unavailable).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_text'] ?? null,
                'error'               => $rawError ?? 'Meta model unavailable.',
            ];
        }

        if ($rawStatus !== 'completed' || empty($raw['identification'])) {
            $isInvalid = ($rawStatus === 'invalid_response')
                || (is_string($rawError) && str_contains(strtolower($rawError), 'json'));
            $status = $isInvalid ? 'invalid_response' : ($rawStatus ?: 'unavailable');

            return [
                'provider_name'       => 'muse_glimmer',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => $status,
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed.',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_text'] ?? null,
                'error'               => $rawError ?? 'Meta vision API call did not return a completed identification. Check MODEL_API_KEY and META_AI_VISION_MODEL configuration.',
            ];
        }

        $ident = $raw['identification'];
        $scientificName = trim($ident['scientific_name'] ?? '');
        $englishName    = trim($ident['english_name'] ?? '');
        $kiribatiName   = trim($ident['kiribati_name'] ?? '');
        $confidence     = isset($ident['confidence']) && is_numeric($ident['confidence'])
            ? round(max(0.0, min(1.0, (float) $ident['confidence'])), 2)
            : null;

        return [
            'provider_name'       => 'muse_glimmer',
            'model_identifier'    => $raw['model'] ?? $model,
            'status'              => 'success',
            'model'               => $raw['model'] ?? $model,
            'is_external_request' => true,
            'suggested_species'   => [
                'scientific_name' => $scientificName ?: 'Unknown',
                'common_name'     => $englishName ?: 'Unknown',
                'kiribati_name'   => $kiribatiName ?: null,
                'ciguatera_risk'  => $ident['ciguatera_risk'] ?? 'low',
            ],
            'scientific_name'     => $scientificName ?: 'Unknown',
            'confidence'          => $confidence,
            'visible_features'    => (array) ($ident['visual_features'] ?? []),
            'reasoning'           => $ident['reasoning'] ?? null,
            'alternatives'        => (array) ($ident['alternatives'] ?? []),
            'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
            'catalogue_match'     => false, // set in attachCatalogueMatch
            'species_id'          => null,
            'raw_response'        => $raw['raw_text'] ?? null,
            'error'               => null,
        ];
    }

    /**
     * Normalize OpenAI Vision response.
     */
    public function normalizeOpenAiResponse(?array $raw, ?string $exceptionError = null, int $durationMs = 0): array
    {
        $model = $this->openAiFishVisionService->getModel();

        if ($exceptionError !== null) {
            $isTimeout = str_contains(strtolower($exceptionError), 'timeout')
                || str_contains(strtolower($exceptionError), 'timed out');
            $isUnavailable = str_contains($exceptionError, 'Could not resolve host')
                || str_contains($exceptionError, 'cURL error 6')
                || str_contains($exceptionError, 'Failed to connect')
                || str_contains($exceptionError, 'Connection refused')
                || str_contains($exceptionError, 'Network is unreachable');

            $status = $isTimeout ? 'timeout' : ($isUnavailable ? 'unavailable' : 'failed');

            return [
                'provider_name'       => 'openai',
                'model_identifier'    => $model,
                'status'              => $status,
                'model'               => $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed. External provider was unavailable or failed.',
                'alternatives'        => [],
                'duration_ms'         => $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => null,
                'error'               => $exceptionError,
            ];
        }

        if (!$raw || in_array(($raw['status'] ?? ''), ['unconfigured', 'disabled'])) {
            return [
                'provider_name'       => 'openai',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'unavailable',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => false,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed. OpenAI Vision is unconfigured.',
                'alternatives'        => [],
                'duration_ms'         => $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => null,
                'error'               => $raw['error'] ?? 'OPENAI_API_KEY is not configured in backend environment.',
            ];
        }

        $rawStatus = $raw['status'] ?? '';
        $rawError = $raw['error'] ?? null;
        $isTimeout = ($rawStatus === 'timeout')
            || (is_string($rawError) && (str_contains(strtolower($rawError), 'timeout') || str_contains(strtolower($rawError), 'timed out')));
        $isUnavailable = is_string($rawError) && (
            str_contains($rawError, 'Could not resolve host') ||
            str_contains($rawError, 'cURL error 6') ||
            str_contains($rawError, 'Failed to connect') ||
            str_contains($rawError, 'Connection refused')
        );

        if ($isTimeout) {
            return [
                'provider_name'       => 'openai',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'timeout',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (request timed out).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_response'] ?? null,
                'error'               => $rawError ?? 'OpenAI Vision request timed out.',
            ];
        }

        if ($isUnavailable) {
            return [
                'provider_name'       => 'openai',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'unavailable',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed. External API endpoint unreachable.',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_response'] ?? null,
                'error'               => $rawError,
            ];
        }

        if ($rawStatus === 'quota_exceeded') {
            return [
                'provider_name'       => 'openai',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'quota_exceeded',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (OpenAI account quota exceeded).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_response'] ?? null,
                'error'               => $rawError ?? 'OpenAI quota exceeded.',
            ];
        }

        if ($rawStatus === 'rate_limited') {
            return [
                'provider_name'       => 'openai',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'rate_limited',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (OpenAI rate limit reached).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_response'] ?? null,
                'error'               => $rawError ?? 'OpenAI rate limit exceeded.',
            ];
        }

        if ($rawStatus === 'unauthorized') {
            return [
                'provider_name'       => 'openai',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => 'unauthorized',
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed (OpenAI authentication failed).',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_response'] ?? null,
                'error'               => $rawError ?? 'OpenAI authentication failed.',
            ];
        }

        if ($rawStatus !== 'completed' || empty($raw['identification'])) {
            $isInvalid = ($rawStatus === 'invalid_response')
                || (is_string($rawError) && str_contains(strtolower($rawError), 'json'));
            $status = $isInvalid ? 'invalid_response' : 'failed';

            return [
                'provider_name'       => 'openai',
                'model_identifier'    => $raw['model'] ?? $model,
                'status'              => $status,
                'model'               => $raw['model'] ?? $model,
                'is_external_request' => true,
                'suggested_species'   => null,
                'scientific_name'     => null,
                'confidence'          => null,
                'visible_features'    => [],
                'reasoning'           => 'No live AI identification was performed.',
                'alternatives'        => [],
                'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
                'catalogue_match'     => false,
                'species_id'          => null,
                'raw_response'        => $raw['raw_response'] ?? null,
                'error'               => $rawError ?? 'OpenAI identification failed.',
            ];
        }

        $ident = $raw['identification'];
        $scientificName = trim($ident['scientific_name'] ?? '');
        $commonName     = trim($ident['common_name'] ?? ($ident['english_name'] ?? ''));
        $kiribatiName   = trim($ident['kiribati_name'] ?? '');
        $confidence     = isset($ident['confidence']) && is_numeric($ident['confidence'])
            ? round(max(0.0, min(1.0, (float) $ident['confidence'])), 2)
            : null;

        return [
            'provider_name'       => 'openai',
            'model_identifier'    => $raw['model'] ?? $model,
            'status'              => 'success',
            'model'               => $raw['model'] ?? $model,
            'is_external_request' => true,
            'suggested_species'   => [
                'scientific_name' => $scientificName ?: 'Unknown',
                'common_name'     => $commonName ?: 'Unknown',
                'kiribati_name'   => $kiribatiName ?: null,
                'ciguatera_risk'  => $ident['ciguatera_risk'] ?? 'unknown',
            ],
            'scientific_name'     => $scientificName ?: 'Unknown',
            'confidence'          => $confidence,
            'visible_features'    => (array) ($ident['visible_traits'] ?? ($ident['visible_features'] ?? [])),
            'reasoning'           => $ident['reasoning'] ?? null,
            'alternatives'        => (array) ($ident['alternatives'] ?? []),
            'duration_ms'         => $raw['latency_ms'] ?? $durationMs,
            'catalogue_match'     => false, // set in attachCatalogueMatch
            'species_id'          => null,
            'raw_response'        => $raw['raw_response'] ?? null,
            'error'               => null,
        ];
    }

    /**
     * Match suggested scientific name against database species catalogue without inventing records.
     */
    protected function attachCatalogueMatch(array $provider): array
    {
        if ($provider['status'] !== 'success' || empty($provider['scientific_name'])) {
            return $provider;
        }

        $scientificName = trim($provider['scientific_name']);
        if ($scientificName === '' || strcasecmp($scientificName, 'Unknown') === 0) {
            return $provider;
        }

        // Search species catalogue by scientific name (exact or case-insensitive)
        $dbMatch = Species::whereRaw('LOWER(scientific_name) = ?', [strtolower($scientificName)])
            ->orWhereRaw('LOWER(scientific_name_as_provided) = ?', [strtolower($scientificName)])
            ->first();

        // Secondary search: if scientific name not found, try Kiribati name or English name
        if (!$dbMatch && !empty($provider['suggested_species']['kiribati_name'])) {
            $dbMatch = Species::whereRaw('LOWER(kiribati_name) = ?', [strtolower($provider['suggested_species']['kiribati_name'])])->first();
        }

        if ($dbMatch) {
            $provider['catalogue_match'] = true;
            $provider['species_id']      = $dbMatch->id;
            // Align Kiribati vernacular name with official catalogue if missing
            if (empty($provider['suggested_species']['kiribati_name'])) {
                $provider['suggested_species']['kiribati_name'] = $dbMatch->kiribati_name;
            }
        } else {
            // Preserve unknown species suggestion without inventing database records
            $provider['catalogue_match'] = false;
            $provider['species_id']      = null;
        }

        return $provider;
    }

    /**
     * Evaluate comparison between both providers.
     */
    protected function evaluateComparison(array $muse, array $openAi): array
    {
        $museOk   = ($muse['status'] === 'success');
        $openAiOk = ($openAi['status'] === 'success');

        // Both failed / unconfigured
        if (!$museOk && !$openAiOk) {
            return [
                'status'  => 'both_failed',
                'details' => [
                    'scientific_name_match'        => false,
                    'catalogue_match'              => false,
                    'human_confirmation_required' => true,
                    'agreed_species'               => null,
                    'confidence_delta'             => null,
                    'shared_features'              => [],
                    'disclaimer'                   => 'Both vision providers failed or were unavailable. Species identification requires manual selection.',
                ],
            ];
        }

        // Single provider result
        if ($museOk !== $openAiOk) {
            $successfulProvider = $museOk ? $muse : $openAi;
            $failedProviderName = $museOk ? 'OpenAI' : 'Meta Muse Glimmer';

            return [
                'status'  => 'single_provider_result',
                'details' => [
                    'scientific_name_match'        => false,
                    'catalogue_match'              => (bool) $successfulProvider['catalogue_match'],
                    'human_confirmation_required' => true,
                    'agreed_species'               => null,
                    'confidence_delta'             => null,
                    'shared_features'              => [],
                    'disclaimer'                   => "Only one provider succeeded ({$successfulProvider['model']}); {$failedProviderName} was unavailable or returned an error. Human confirmation required.",
                ],
            ];
        }

        // Both providers succeeded — check confidence threshold
        $museConf   = (float) ($muse['confidence'] ?? 0.0);
        $openAiConf = (float) ($openAi['confidence'] ?? 0.0);

        // Insufficient evidence if either provider confidence < 0.50
        if ($museConf < 0.50 || $openAiConf < 0.50) {
            return [
                'status'  => 'insufficient_evidence',
                'details' => [
                    'scientific_name_match'        => $this->compareScientificNames($muse['scientific_name'], $openAi['scientific_name']),
                    'catalogue_match'              => $muse['catalogue_match'] || $openAi['catalogue_match'],
                    'human_confirmation_required' => true,
                    'agreed_species'               => null,
                    'confidence_delta'             => round(abs($museConf - $openAiConf), 2),
                    'shared_features'              => $this->computeSharedFeatures($muse['visible_features'], $openAi['visible_features']),
                    'disclaimer'                   => 'One or both providers reported low confidence (< 0.50). Image evidence is insufficient for reliable automated guidance.',
                ],
            ];
        }

        // Check scientific name match
        $isNameMatch = $this->compareScientificNames($muse['scientific_name'], $openAi['scientific_name']);
        $isSameCatalogSpecies = ($muse['species_id'] !== null && $muse['species_id'] === $openAi['species_id']);
        $isAgreement = $isNameMatch || $isSameCatalogSpecies;

        $confidenceDelta = round(abs($museConf - $openAiConf), 2);
        $sharedFeatures  = $this->computeSharedFeatures($muse['visible_features'], $openAi['visible_features']);

        if ($isAgreement) {
            $agreedName = $muse['scientific_name'];
            $kName = $muse['suggested_species']['kiribati_name'] ?? ($openAi['suggested_species']['kiribati_name'] ?? null);
            $agreedDisplay = $kName ? "{$agreedName} ({$kName})" : $agreedName;

            return [
                'status'  => 'agreement',
                'details' => [
                    'scientific_name_match'        => true,
                    'catalogue_match'              => $muse['catalogue_match'] || $openAi['catalogue_match'],
                    'human_confirmation_required' => true,
                    'agreed_species'               => $agreedDisplay,
                    'confidence_delta'             => $confidenceDelta,
                    'shared_features'              => $sharedFeatures,
                    'disclaimer'                   => 'Both providers agree on species classification. AI identification remains advisory; human confirmation is mandatory.',
                ],
            ];
        }

        return [
            'status'  => 'disagreement',
            'details' => [
                'scientific_name_match'        => false,
                'catalogue_match'              => $muse['catalogue_match'] || $openAi['catalogue_match'],
                'human_confirmation_required' => true,
                'agreed_species'               => null,
                'confidence_delta'             => $confidenceDelta,
                'shared_features'              => $sharedFeatures,
                'disclaimer'                   => "Providers disagreed on identification ({$muse['scientific_name']} vs {$openAi['scientific_name']}). Please review morphological features and confirm the true species.",
            ],
        ];
    }

    /**
     * Compare scientific names with binomial normalization and case-insensitivity.
     */
    protected function compareScientificNames(?string $name1, ?string $name2): bool
    {
        if (empty($name1) || empty($name2)) {
            return false;
        }

        $clean1 = strtolower(trim(preg_replace('/\s+/', ' ', $name1)));
        $clean2 = strtolower(trim(preg_replace('/\s+/', ' ', $name2)));

        if ($clean1 === 'unknown' || $clean2 === 'unknown') {
            return false;
        }

        return $clean1 === $clean2;
    }

    /**
     * Extract shared keywords or features between providers.
     */
    protected function computeSharedFeatures(array $features1, array $features2): array
    {
        $shared = [];
        $text2 = strtolower(implode(' ', $features2));

        foreach ($features1 as $f) {
            $words = array_filter(explode(' ', strtolower($f)), fn ($w) => strlen($w) > 4);
            foreach ($words as $w) {
                if (str_contains($text2, $w)) {
                    $shared[] = $f;
                    break;
                }
            }
        }

        return array_values(array_unique(array_slice($shared, 0, 3)));
    }

    /**
     * Generate offline catalogue reference assistance.
     *
     * Provides authentic Kiribati species catalog reference context for human review.
     * Never pretends to have visually analysed an image without an AI model.
     * Never invents fake confidence scores (confidence is strictly null).
     */
    public function generateOfflineCatalogueReference(
        string $hint = '',
        array  $referenceSpecies = []
    ): array {
        $kb = \App\Http\Controllers\AiIdentificationController::getKnowledgeBase();
        $cleanHint = strtolower(trim($hint));

        $matchedSpecies = null;
        if ($cleanHint !== '') {
            foreach ($kb as $item) {
                if (
                    str_contains(strtolower($item['kiribati_name']), $cleanHint) ||
                    str_contains(strtolower($item['english_name']), $cleanHint) ||
                    str_contains(strtolower($item['scientific_name']), $cleanHint)
                ) {
                    $matchedSpecies = $item;
                    break;
                }
            }
            if (!$matchedSpecies) {
                $dbMatch = Species::where('kiribati_name', 'like', "%{$cleanHint}%")
                    ->orWhere('english_name', 'like', "%{$cleanHint}%")
                    ->orWhere('scientific_name', 'like', "%{$cleanHint}%")
                    ->first();
                if ($dbMatch) {
                    $matchedSpecies = [
                        'id'              => $dbMatch->id,
                        'kiribati_name'   => $dbMatch->kiribati_name,
                        'english_name'    => $dbMatch->english_name,
                        'scientific_name' => $dbMatch->scientific_name,
                        'ciguatera_risk'  => $dbMatch->ciguatera_risk ?? 'low',
                        'visual_features' => (array) ($dbMatch->visual_features ?? []),
                    ];
                }
            }
        }

        // Candidates for manual human inspection (clearly labeled unverified reference)
        $referenceList = [];
        foreach ($kb as $candidate) {
            $referenceList[] = [
                'scientific_name' => $candidate['scientific_name'],
                'english_name'    => $candidate['english_name'],
                'kiribati_name'   => $candidate['kiribati_name'],
                'ciguatera_risk'  => $candidate['ciguatera_risk'] ?? 'low',
                'visual_features' => $candidate['visual_features'] ?? [],
                'confidence'      => null, // Never fake confidence
            ];
            if (count($referenceList) >= 4) break;
        }

        if ($matchedSpecies) {
            return [
                'status'                      => 'fallback',
                'identification'              => 'Catalogue hint reference (unverified)',
                'suggested_species'           => [
                    'scientific_name' => $matchedSpecies['scientific_name'],
                    'common_name'     => $matchedSpecies['english_name'],
                    'kiribati_name'   => $matchedSpecies['kiribati_name'],
                    'ciguatera_risk'  => $matchedSpecies['ciguatera_risk'] ?? 'low',
                ],
                'scientific_name'             => $matchedSpecies['scientific_name'],
                'confidence'                  => null, // Honest: No live vision model ran
                'confidence_text'             => 'Not available',
                'visible_features'            => (array) ($matchedSpecies['visual_features'] ?? []),
                'reasoning'                   => "Catalogue reference matched observer hint '{$hint}'. No live AI vision identification was performed.",
                'reference_species'           => $referenceList,
                'human_confirmation_required' => true,
            ];
        }

        return [
            'status'                      => 'insufficient_evidence',
            'identification'              => 'No reliable match',
            'suggested_species'           => null,
            'scientific_name'             => null,
            'confidence'                  => null,
            'confidence_text'             => 'Not available',
            'visible_features'            => [],
            'reasoning'                   => 'No live AI identification was performed. Offline catalogue cannot visually analyse photographs without external vision models.',
            'reference_species'           => $referenceList,
            'human_confirmation_required' => true,
        ];
    }

    /**
     * Backward-compatible fallback helper that complies with Section 4, 5, 6 rules.
     * Never fabricates confidence or forces species selection.
     */
    public function generateGroundedFallback(
        string $provider,
        string $imageUrlOrDataUrl,
        string $hint = '',
        array  $referenceSpecies = [],
        int    $durationMs = 0
    ): array {
        $model = ($provider === 'openai')
            ? $this->openAiFishVisionService->getModel()
            : $this->metaAiService->getVisionModel();

        $ref = $this->generateOfflineCatalogueReference($hint, $referenceSpecies);

        return [
            'provider_name'       => $provider,
            'model_identifier'    => $model,
            'status'              => $ref['status'] === 'fallback' ? 'fallback' : 'unavailable',
            'model'               => $model,
            'is_external_request' => false,
            'suggested_species'   => $ref['suggested_species'],
            'scientific_name'     => $ref['scientific_name'],
            'confidence'          => null, // Mandatory: never fake confidence
            'visible_features'    => $ref['visible_features'],
            'reasoning'           => $ref['reasoning'],
            'alternatives'        => $ref['reference_species'],
            'duration_ms'         => $durationMs,
            'catalogue_match'     => false,
            'species_id'          => null,
            'raw_response'        => null,
            'error'               => "Provider {$provider} was unavailable. No live AI identification was performed.",
        ];
    }
}
