<?php

namespace App\Services;

use App\Services\Concerns\ResolvesApiEndpoints;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta AI Service
 * Manages outbound API communication with the Meta Model API (https://api.meta.ai/v1/responses)
 * Model: muse-wonder-1.0
 * 
 * Security rule: MODEL_API_KEY is strictly encapsulated here and never returned to
 * clients, log files, or version control.
 */
class MetaAiService
{
    use ResolvesApiEndpoints;
    protected string $endpoint;
    protected string $visionEndpoint;
    protected string $model;
    protected string $visionModel;
    protected ?string $apiKey;
    protected int $timeout;
    protected int $visionTimeout;

    public function __construct(
        ?string $endpoint = null,
        ?string $model = null,
        ?string $apiKey = null,
        ?int $timeout = null,
        ?string $visionModel = null,
        ?int $visionTimeout = null
    ) {
        $this->endpoint       = $endpoint ?? config('services.meta_ai.endpoint', 'https://api.meta.ai/v1/responses');
        $this->visionEndpoint = config('services.meta_ai.vision_endpoint', $this->endpoint);
        $this->model          = $model ?? config('services.meta_ai.model', 'muse-wonder-1.0');
        $this->visionModel    = $visionModel ?? config('services.meta_ai.vision_model', 'muse-spark-1.3');
        $this->apiKey         = $apiKey ?? config('services.meta_ai.api_key');
        $this->timeout        = $timeout ?? (int) config('services.meta_ai.timeout', 15);
        $this->visionTimeout  = $visionTimeout ?? (int) config('services.meta_ai.vision_timeout', 30);
    }

    /**
     * Check if the Meta AI service has an API key configured.
     * Note: Never returns the key value itself.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    /**
     * Get the configured model identifier.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the configured multimodal vision model identifier (Muse Glimmer).
     */
    public function getVisionModel(): string
    {
        return $this->visionModel;
    }

    /**
     * Get the configured API endpoint URL.
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Query Meta Model API with a text prompt.
     *
     * @param string $prompt
     * @param array $options Optional overrides (model, temperature, etc.)
     * @return array Standardized result array
     */
    public function queryText(string $prompt, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'unconfigured',
                'model' => $this->model,
                'endpoint' => $this->endpoint,
                'text' => null,
                'raw_status' => 'unconfigured',
                'latency_ms' => 0,
                'error' => 'MODEL_API_KEY is not configured in the server environment.',
            ];
        }

        $targetModel = $options['model'] ?? $this->model;
        $startTime = microtime(true);

        // Safe logging without credentials
        Log::info('MetaAiService: Initiating text request', [
            'model' => $targetModel,
            'endpoint' => $this->endpoint,
            'prompt_length' => strlen($prompt),
        ]);

        try {
            $payload = [
                'model' => $targetModel,
                'input' => $prompt,
            ];

            $response = Http::withOptions($this->getCurlOptions($this->endpoint))
                ->timeout($this->timeout)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post($this->endpoint, $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $data = $response->json();
                $extractedText = $this->extractTextFromResponse($data);

                Log::info('MetaAiService: Text request succeeded', [
                    'model' => $targetModel,
                    'status_code' => $response->status(),
                    'latency_ms' => $latencyMs,
                    'response_id' => $data['id'] ?? null,
                ]);

                return [
                    'status' => ($data['status'] ?? '') === 'completed' ? 'completed' : 'completed',
                    'model' => $data['model'] ?? $targetModel,
                    'endpoint' => $this->endpoint,
                    'text' => $extractedText,
                    'raw_status' => $data['status'] ?? 'completed',
                    'id' => $data['id'] ?? null,
                    'latency_ms' => $latencyMs,
                    'error' => null,
                ];
            }

            // HTTP Error (4xx, 5xx)
            $statusCode = $response->status();
            $errorMessage = $this->extractErrorMessage($response->json(), $response->body(), $statusCode);

            Log::warning('MetaAiService: Request returned non-200 status', [
                'model' => $targetModel,
                'status_code' => $statusCode,
                'latency_ms' => $latencyMs,
                'error' => $errorMessage,
            ]);

            return [
                'status' => 'failed',
                'model' => $targetModel,
                'endpoint' => $this->endpoint,
                'text' => null,
                'raw_status' => 'error_' . $statusCode,
                'latency_ms' => $latencyMs,
                'status_code' => $statusCode,
                'error' => $errorMessage,
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            // Never log exception traces that could contain HTTP Authorization headers
            Log::error('MetaAiService: Network or connection exception occurred', [
                'model' => $targetModel,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'latency_ms' => $latencyMs,
            ]);

            return [
                'status' => 'failed',
                'model' => $targetModel,
                'endpoint' => $this->endpoint,
                'text' => null,
                'raw_status' => 'exception',
                'latency_ms' => $latencyMs,
                'error' => 'Connection to Meta AI failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Execute a quick health check ping against the Meta Model API.
     *
     * @return array Health summary
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'unconfigured',
                'endpoint' => $this->endpoint,
                'model' => $this->model,
                'has_api_key' => false,
                'latency_ms' => 0,
                'response' => null,
                'message' => 'MODEL_API_KEY is not configured in the server environment. Please set MODEL_API_KEY in backend/.env.',
                'tested_at' => now()->toIso8601String(),
                'disclaimer' => 'AI model responses are advisory suggestions and cannot determine fish toxicity.',
            ];
        }

        $probePrompt = 'Respond with OK for Kiribati fisheries assistant connectivity test.';
        $result = $this->queryText($probePrompt);

        $isConnected = ($result['status'] === 'completed');

        return [
            'status' => $isConnected ? 'connected' : 'degraded',
            'endpoint' => $this->endpoint,
            'model' => $result['model'] ?? $this->model,
            'has_api_key' => true,
            'latency_ms' => $result['latency_ms'] ?? 0,
            'response' => $result['text'] ?? null,
            'error' => $result['error'] ?? null,
            'message' => $isConnected
                ? 'Meta Model API connection test passed successfully.'
                : 'Meta Model API request failed or returned degraded response.',
            'tested_at' => now()->toIso8601String(),
            'disclaimer' => 'AI model responses are advisory suggestions and cannot determine fish toxicity.',
        ];
    }

    /**
     * Query Muse Wonder 1.0 with a structured fisheries assistance prompt.
     *
     * Combines:
     *  - SAM 3.1 segmentation evidence (object count, bounding box presence)
     *  - Kiribati morphological knowledge-base candidate data
     *  - Fisher-supplied hint text
     *
     * Returns advisory notes ONLY — never species names, scientific names, or
     * confidence values. Species identification stays with the KB + human
     * confirmation workflow (ADR-002, ADR-009).
     *
     * The prompt is structured so Muse Wonder cannot hallucinate species by:
     *   1. Never asking it to name a species
     *   2. Always grounding it with the KB-selected candidate name as context
     *   3. Asking only for biology, habitat, and consumption safety notes
     *   4. Explicitly instructing it not to invent information
     *
     * @param string $kbKiribatiName   Verified KB candidate name
     * @param string $kbEnglishName    Verified KB English name
     * @param string $kbScientificName Verified KB scientific name
     * @param array  $samEvidence      SAM 3.1 result array (may be empty/unconfigured)
     * @param string $hint             Optional fisher-supplied hint text
     * @param array  $options          Optional MetaAiService overrides
     * @return array{status:string, advisory:string|null, model:string, latency_ms:int, error:string|null}
     */
    public function queryFisheriesAssistant(
        string $kbKiribatiName,
        string $kbEnglishName,
        string $kbScientificName,
        array  $samEvidence = [],
        string $hint = '',
        array  $options = []
    ): array {
        if (!$this->isConfigured()) {
            return [
                'status'     => 'unconfigured',
                'advisory'   => null,
                'model'      => $this->model,
                'latency_ms' => 0,
                'error'      => 'MODEL_API_KEY is not configured in the server environment.',
            ];
        }

        // ── Build SAM evidence summary (text only; never hallucinate) ──────
        $samSummary = 'SAM 3.1 segmentation: unconfigured or unavailable.';
        if (!empty($samEvidence['status'])) {
            $status = $samEvidence['status'];
            if ($status === 'ok') {
                $count  = (int) ($samEvidence['object_count'] ?? 0);
                $hasBbx = !empty($samEvidence['best_box']);
                $samSummary = "SAM 3.1 segmented {$count} object(s) matching concept 'fish'."
                    . ($hasBbx ? ' Largest bounding box confirmed present.' : ' No bounding box returned.');
            } elseif ($status === 'unconfigured') {
                $samSummary = 'SAM 3.1 segmentation: not configured (API key absent).';
            } elseif ($status === 'image_unavailable') {
                $samSummary = 'SAM 3.1 segmentation: image bytes unavailable for analysis.';
            } else {
                $samSummary = "SAM 3.1 segmentation status: {$status}.";
            }
        }

        // ── Compose the fisheries advisory prompt ─────────────────────────
        $hintLine = $hint ? "Fisher observation hint: \"{$hint}\"." : '';
        $prompt = <<<PROMPT
You are a fisheries biology assistant for the Kiribati Marine Fisheries Management Department.

Context
-------
Candidate species (from Kiribati morphological knowledge base, NOT your classification):
  - Kiribati name: {$kbKiribatiName}
  - English name:  {$kbEnglishName}
  - Scientific name: {$kbScientificName}

Image evidence: {$samSummary}
{$hintLine}

Task
----
Provide brief, factual advisory notes (2–4 sentences) covering:
1. Typical habitat and distribution in central Pacific / Kiribati EEZ waters.
2. Subsistence fishing significance or ecological role.
3. Any known ciguatoxin bioaccumulation pattern (do NOT make a safety determination — that requires laboratory testing).

Rules you MUST follow:
- Do NOT suggest alternative species names or scientific names.
- Do NOT invent confidence scores or catch statistics.
- Do NOT claim a photograph proves species identity — only laboratory analysis can do that.
- If you are uncertain about any fact, say so explicitly.
- Respond in plain English suitable for a fisheries officer or fisher.
PROMPT;

        $result = $this->queryText(trim($prompt), $options);

        return [
            'status'     => $result['status'],
            'advisory'   => ($result['status'] === 'completed' && !empty($result['text']))
                               ? $result['text']
                               : null,
            'model'      => $result['model'] ?? $this->model,
            'latency_ms' => $result['latency_ms'] ?? 0,
            'error'      => $result['error'] ?? null,
        ];
    }

    /**
     * Investigate whether Muse Wonder 1.0 accepts image inputs at this endpoint.
     *
     * Background (ADR-016, Day 4 integration report):
     *   - muse-wonder-1.0 is a TEXT model on the Meta Model API.
     *   - The SAM-Online-Agent live-tested the sam-3.1 model (same key, same endpoint)
     *     for image segmentation and confirmed HTTP 200 + 17 objects.
     *   - No live call to muse-wonder-1.0 with an image input was ever made (no API
     *     key in the sandbox, and the Day 4 audit recorded it BLOCKED).
     *   - This method uses a MOCK-FRIENDLY guard so tests never bill the API.
     *
     * When called with a real key, it probes the endpoint with the OpenAI-compatible
     * multi-modal message format and records whether the model accepted or rejected it.
     *
     * @param string $imageUrl A publicly reachable image URL (for the probe payload)
     * @return array
     */
    public function investigateImageModality(string $imageUrl): array
    {
        if (!$this->isConfigured()) {
            return [
                'supports_image'   => null,  // unknown — never tested
                'tested'           => false,
                'model'            => $this->model,
                'reason'           => 'MODEL_API_KEY is not configured. Cannot probe image modality.',
                'known_text_only'  => true,   // muse-wonder-1.0 is documented as text-only (ADR-016)
                'sam_image_model'  => 'sam-3.1',
                'recommendation'   => 'Use sam-3.1 (same key, same endpoint) for image segmentation evidence. Use muse-wonder-1.0 for text-based fisheries advisory reasoning.',
                'investigated_at'  => now()->toIso8601String(),
            ];
        }

        try {
            // Attempt standard Meta Responses multi-modal structure
            $payload = [
                'model' => $this->model,
                'input' => [
                    [
                        'type'    => 'message',
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'input_text',  'text'      => 'Briefly describe this marine specimen.'],
                            ['type' => 'input_image', 'image_url' => $this->toDataUrl($imageUrl) ?? $imageUrl],
                        ],
                    ],
                ],
            ];

            $response = Http::withOptions($this->getCurlOptions($this->endpoint))
                ->timeout($this->timeout)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post($this->endpoint, $payload);

            $supported = $response->successful();
            $statusCode = $response->status();
            $text = $supported ? $this->extractTextFromResponse($response->json() ?? []) : null;

            return [
                'supports_image'   => $supported,
                'tested'           => true,
                'model'            => $this->model,
                'status_code'      => $statusCode,
                'response_text'    => $text,
                'reason'           => $supported
                    ? 'Model accepted multi-modal image_url input and returned a response.'
                    : 'Model rejected multi-modal image payload (HTTP ' . $statusCode . '). Likely text-only.',
                'known_text_only'  => !$supported,
                'sam_image_model'  => 'sam-3.1',
                'recommendation'   => $supported
                    ? 'muse-wonder-1.0 may support image input — validate response quality before production use.'
                    : 'Use sam-3.1 for image segmentation and muse-wonder-1.0 for text-based advisory reasoning.',
                'investigated_at'  => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            return [
                'supports_image'   => false,
                'tested'           => true,
                'model'            => $this->model,
                'reason'           => 'Image modality probe failed: ' . $e->getMessage(),
                'known_text_only'  => true,
                'sam_image_model'  => 'sam-3.1',
                'recommendation'   => 'Use sam-3.1 for image segmentation and muse-wonder-1.0 for text-based advisory reasoning.',
                'investigated_at'  => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Resolve image URL or path to a base64 data URL for Meta Model API.
     */
    public function toDataUrl(string $imageUrlOrDataUrl): ?string
    {
        $raw = trim($imageUrlOrDataUrl);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'data:image/')) {
            return $raw;
        }

        // Local file path
        if (is_file($raw) && is_readable($raw)) {
            $bytes = @file_get_contents($raw);
            if ($bytes !== false && strlen($bytes) <= 10485760) {
                $mime = mime_content_type($raw) ?: 'image/jpeg';
                return 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }
        }

        // Local uploads path referenced by URL
        if (str_contains($raw, 'uploads/catches/') || str_contains($raw, 'uploads/species/')) {
            $path = parse_url($raw, PHP_URL_PATH);
            if ($path) {
                $local = public_path(ltrim($path, '/'));
                if (is_file($local) && is_readable($local)) {
                    $bytes = @file_get_contents($local);
                    if ($bytes !== false && strlen($bytes) <= 10485760) {
                        $mime = mime_content_type($local) ?: 'image/jpeg';
                        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
                    }
                }
            }
        }

        // Remote HTTP/HTTPS URL
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            if (app()->environment('testing')) {
                return $raw;
            }
            try {
                $res = Http::timeout(10)->get($raw);
                if ($res->successful()) {
                    $bytes = $res->body();
                    $contentType = (string) $res->header('Content-Type', 'image/jpeg');
                    if ($bytes !== '' && strlen($bytes) <= 10485760 && str_contains($contentType, 'image')) {
                        $mime = trim(explode(';', $contentType)[0]);
                        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
                    }
                }
            } catch (\Throwable) {
                // Return original if fetch fails
            }
        }

        return $raw;
    }

    /**
     * Send a multimodal vision request with an image and text prompt to Meta Model API.
     *
     * @param string $imageUrlOrDataUrl
     * @param string $prompt
     * @param array  $options
     * @return array Standardized result with parsed JSON or error
     */
    public function sendVisionRequest(string $imageUrlOrDataUrl, string $prompt, array $options = []): array
    {
        $targetModel = $options['model'] ?? $this->visionModel;

        // Allow disabling Meta vision entirely via META_AI_VISION_ENABLED=false.
        // When disabled, skip the API call and return vision_disabled so the
        // caller falls back to the morphological knowledge base cleanly.
        if (!config('services.meta_ai.vision_enabled', true)) {
            return [
                'status'     => 'vision_disabled',
                'model'      => $targetModel,
                'parsed'     => null,
                'raw_text'   => null,
                'latency_ms' => 0,
                'error'      => null,
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'status'     => 'unconfigured',
                'model'      => $targetModel,
                'parsed'     => null,
                'raw_text'   => null,
                'latency_ms' => 0,
                'error'      => 'MODEL_API_KEY is not configured in the server environment.',
            ];
        }

        $startTime = microtime(true);

        try {
            $dataUrl = $this->toDataUrl($imageUrlOrDataUrl);
            if (empty($dataUrl)) {
                return [
                    'status'     => 'invalid_input',
                    'model'      => $targetModel,
                    'parsed'     => null,
                    'raw_text'   => null,
                    'latency_ms' => 0,
                    'error'      => 'Could not resolve image data for vision request.',
                ];
            }

            // Auto-detect payload format from vision endpoint URL.
            // /responses endpoint uses Meta Responses API format (input[]).
            // /chat/completions endpoint uses OpenAI-compatible format (messages[]).
            $usesChatCompletions = str_contains($this->visionEndpoint, 'chat/completions');

            if ($usesChatCompletions) {
                $payload = [
                    'model'    => $targetModel,
                    'messages' => [
                        [
                            'role'    => 'user',
                            'content' => [
                                ['type' => 'text',      'text'      => trim($prompt)],
                                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                            ],
                        ],
                    ],
                    'max_tokens' => 1200,
                ];
            } else {
                $payload = [
                    'model' => $targetModel,
                    'input' => [
                        [
                            'type'    => 'message',
                            'role'    => 'user',
                            'content' => [
                                ['type' => 'input_text',  'text'      => trim($prompt)],
                                ['type' => 'input_image', 'image_url' => $dataUrl],
                            ],
                        ],
                    ],
                ];
            }

            $response = Http::withOptions($this->getCurlOptions($this->visionEndpoint))
                ->timeout($this->visionTimeout)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post($this->visionEndpoint, $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                $extractedText = $this->extractTextFromResponse($data);
                $parsed = $this->parseJsonPayload($extractedText);

                return [
                    'status'     => 'completed',
                    'model'      => $data['model'] ?? $targetModel,
                    'parsed'     => $parsed,
                    'raw_text'   => $extractedText,
                    'latency_ms' => $latencyMs,
                    'error'      => null,
                ];
            }

            $statusCode = $response->status();
            $errorMessage = $this->extractErrorMessage($response->json(), $response->body(), $statusCode);

            if ($statusCode === 429) {
                $status = 'rate_limited';
            } elseif ($statusCode === 401 || $statusCode === 403) {
                $status = 'unauthorized';
            } elseif ($statusCode === 404) {
                $status = 'model_unavailable';
            } elseif ($statusCode === 408 || $statusCode === 504) {
                $status = 'timeout';
            } elseif ($statusCode === 400) {
                $status = 'invalid_payload';
            } else {
                $status = 'failed';
            }

            return [
                'status'      => $status,
                'model'       => $targetModel,
                'parsed'      => null,
                'raw_text'    => null,
                'latency_ms'  => $latencyMs,
                'status_code' => $statusCode,
                'error'       => $errorMessage,
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $msg = $e->getMessage();
            $isTimeout = str_contains(strtolower($msg), 'timeout') || str_contains(strtolower($msg), 'timed out');

            return [
                'status'     => $isTimeout ? 'timeout' : 'failed',
                'model'      => $targetModel,
                'parsed'     => null,
                'raw_text'   => null,
                'latency_ms' => $latencyMs,
                'error'      => 'Connection to Meta vision API failed: ' . $msg,
            ];
        }
    }

    /**
     * Stage 1: Match against catalogued backend species that have reference photographs.
     *
     * @param string $imageUrlOrDataUrl Public image URL or base64 data URL
     * @param array  $speciesWithPhotos Known species records that possess reference photos
     * @param array  $samEvidence       SAM 3.1 segmentation evidence
     * @param string $hint              Fisher hint text
     * @param array  $options           Optional parameter overrides
     * @return array Standardized result with matched bool and identification
     */
    public function matchAgainstDbPhotos(
        string $imageUrlOrDataUrl,
        array $speciesWithPhotos = [],
        array $samEvidence = [],
        string $hint = '',
        array $options = []
    ): array {
        if (empty($speciesWithPhotos)) {
            return [
                'status'         => 'no_match',
                'matched'        => false,
                'model'          => $this->visionModel,
                'identification' => null,
                'latency_ms'     => 0,
                'error'          => 'No reference species with photos available in database.',
            ];
        }

        $formatted = [];
        foreach ($speciesWithPhotos as $spec) {
            $kName = $spec['kiribati_name'] ?? '';
            $eName = $spec['english_name'] ?? '';
            $sName = $spec['scientific_name'] ?? '';
            if ($kName && $eName) {
                $formatted[] = "- {$kName} ({$eName} / {$sName}) [Catalogued reference photo available]";
            }
        }
        $speciesContext = "Kiribati fisheries catalogued reference species with photographs:\n"
            . implode("\n", array_slice($formatted, 0, 20)) . "\n\n";

        $samSummary = '';
        if (!empty($samEvidence['status']) && $samEvidence['status'] === 'ok') {
            $count = (int) ($samEvidence['object_count'] ?? 0);
            $samSummary = "Image segmentation evidence: SAM 3.1 segmented {$count} fish object(s).\n";
        }

        $hintLine = $hint ? "Fisher observation hint: \"{$hint}\".\n" : '';

        $prompt = <<<PROMPT
You are an expert marine fisheries classifier in Kiribati.
STAGE 1 — DATABASE PHOTO MATCHING:
Examine this uploaded fish photograph.
Your task is to determine if this specimen visually matches one of the species catalogued with reference photographs in the Kiribati fisheries database:

{$speciesContext}{$samSummary}{$hintLine}
Instructions:
1. Compare the uploaded photo's visual appearance (body shape, color patterns, fin morphology, facial markings) against the catalogued reference species above.
2. If this fish clearly matches one of the listed species with high visual certainty (confidence >= 0.75), set "matched": true.
3. If the fish does NOT clearly match any of the reference species with photos above, or if you are uncertain (confidence < 0.75), set "matched": false so Stage 2 (morphological traits) can evaluate it.

Return a strict JSON object:
{
  "matched": true,
  "kiribati_name": "<authentic Kiribati local name of matching species, e.g. Te Bwere>",
  "english_name": "<English common name of matching species, e.g. Paddletail Snapper>",
  "scientific_name": "<Binomial scientific name, e.g. Lutjanus gibbus>",
  "confidence": <decimal between 0.50 and 0.99, e.g. 0.95>,
  "visual_features": [
    "<matching visual feature 1>",
    "<matching visual feature 2>"
  ],
  "reasoning": "<concise explanation of visual photo match or why no match was found>"
}

Rules:
- If matched is false, set confidence to your best estimate but mark "matched": false.
- Output ONLY the JSON object. Do not include markdown codeblocks or conversational text.
PROMPT;

        $res = $this->sendVisionRequest($imageUrlOrDataUrl, $prompt, $options);
        if ($res['status'] !== 'completed') {
            return [
                'status'         => $res['status'],
                'matched'        => false,
                'model'          => $res['model'],
                'identification' => null,
                'raw_text'       => $res['raw_text'] ?? null,
                'latency_ms'     => $res['latency_ms'],
                'error'          => $res['error'],
            ];
        }

        $parsed = $res['parsed'];
        $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;
        $isMatched = !empty($parsed['matched']) || (!isset($parsed['matched']) && $confidence >= 0.75);

        if ($isMatched && !empty($parsed['kiribati_name']) && !empty($parsed['english_name']) && $confidence >= 0.75) {
            return [
                'status'         => 'completed',
                'matched'        => true,
                'model'          => $res['model'],
                'identification' => [
                    'kiribati_name'   => trim($parsed['kiribati_name']),
                    'english_name'    => trim($parsed['english_name']),
                    'scientific_name' => trim($parsed['scientific_name'] ?? ''),
                    'confidence'      => min(0.99, max(0.50, $confidence)),
                    'visual_features' => array_values(array_filter((array) ($parsed['visual_features'] ?? []))),
                    'reasoning'       => $parsed['reasoning'] ?? null,
                ],
                'raw_text'       => $res['raw_text'],
                'latency_ms'     => $res['latency_ms'],
                'error'          => null,
            ];
        }

        return [
            'status'         => 'no_match',
            'matched'        => false,
            'model'          => $res['model'],
            'identification' => null,
            'raw_text'       => $res['raw_text'],
            'latency_ms'     => $res['latency_ms'],
            'reasoning'      => $parsed['reasoning'] ?? 'Specimen does not visually match catalogued reference species photos.',
            'error'          => null,
        ];
    }

    /**
     * Stage 2: Match by morphological traits (fin shape, body profile, scales, markings)
     * against curated Kiribati traits catalog.
     *
     * @param string $imageUrlOrDataUrl Public image URL or base64 data URL
     * @param array  $speciesWithTraits Known species records with visual_features
     * @param array  $samEvidence       SAM 3.1 segmentation evidence
     * @param string $hint              Fisher hint text
     * @param array  $options           Optional parameter overrides
     * @return array Standardized result with matched bool and identification
     */
    public function matchByMorphologicalTraits(
        string $imageUrlOrDataUrl,
        array $speciesWithTraits = [],
        array $samEvidence = [],
        string $hint = '',
        array $options = []
    ): array {
        if (empty($speciesWithTraits)) {
            return [
                'status'         => 'no_match',
                'matched'        => false,
                'model'          => $this->visionModel,
                'identification' => null,
                'latency_ms'     => 0,
                'error'          => 'No species with morphological traits provided.',
            ];
        }

        $formatted = [];
        foreach ($speciesWithTraits as $spec) {
            $kName = $spec['kiribati_name'] ?? '';
            $eName = $spec['english_name'] ?? '';
            $sName = $spec['scientific_name'] ?? '';
            $feats = (array) ($spec['visual_features'] ?? []);
            if ($kName && !empty($feats)) {
                $traitBullets = implode("\n    * ", array_slice($feats, 0, 3));
                $formatted[] = "- {$kName} ({$eName} / {$sName}):\n    * {$traitBullets}";
            }
        }
        $traitsContext = "Curated Kiribati morphological trait profiles:\n"
            . implode("\n", array_slice($formatted, 0, 15)) . "\n\n";

        $samSummary = '';
        if (!empty($samEvidence['status']) && $samEvidence['status'] === 'ok') {
            $count = (int) ($samEvidence['object_count'] ?? 0);
            $samSummary = "Image segmentation evidence: SAM 3.1 confirmed {$count} fish object(s).\n";
        }

        $hintLine = $hint ? "Fisher observation hint: \"{$hint}\".\n" : '';

        $prompt = <<<PROMPT
You are an expert marine fisheries biologist in Kiribati.
STAGE 2 — MORPHOLOGICAL TRAITS MATCHING:
The uploaded specimen did not produce a confident match in Stage 1 photo cataloging.
Now examine the specific visible morphological traits in this fish photograph:
- Caudal/tail fin shape, dorsal and anal fin characteristics
- Body profile, lateral line markings, coloration, bar/spot patterns
- Jaw structure, mouth orientation, opercular spines

Compare against these curated morphological trait profiles from Kiribati:
{$traitsContext}{$samSummary}{$hintLine}
Instructions:
1. Determine if the visible morphological traits match any curated trait profile above with confidence >= 0.65.
2. If YES, set "matched": true and specify the observed matching traits.
3. If NO (or if confidence < 0.65), set "matched": false so Stage 3 (broad web vision) can evaluate it.

Return a strict JSON object:
{
  "matched": true,
  "kiribati_name": "<matching Kiribati local name, e.g. Te Ingimea>",
  "english_name": "<matching English name, e.g. Yellowfin Tuna>",
  "scientific_name": "<matching Binomial scientific name, e.g. Thunnus albacares>",
  "confidence": <decimal between 0.50 and 0.99, e.g. 0.88>,
  "visual_features": [
    "<observed morphological trait 1>",
    "<observed morphological trait 2>"
  ],
  "reasoning": "<concise explanation of morphological trait correspondence or why no match was found>"
}

Rules:
- Output ONLY the JSON object. Do not include markdown codeblocks or conversational text.
PROMPT;

        $res = $this->sendVisionRequest($imageUrlOrDataUrl, $prompt, $options);
        if ($res['status'] !== 'completed') {
            return [
                'status'         => $res['status'],
                'matched'        => false,
                'model'          => $res['model'],
                'identification' => null,
                'raw_text'       => $res['raw_text'] ?? null,
                'latency_ms'     => $res['latency_ms'],
                'error'          => $res['error'],
            ];
        }

        $parsed = $res['parsed'];
        $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;
        $isMatched = !empty($parsed['matched']) || (!isset($parsed['matched']) && $confidence >= 0.65);

        if ($isMatched && !empty($parsed['kiribati_name']) && !empty($parsed['english_name']) && $confidence >= 0.65) {
            return [
                'status'         => 'completed',
                'matched'        => true,
                'model'          => $res['model'],
                'identification' => [
                    'kiribati_name'   => trim($parsed['kiribati_name']),
                    'english_name'    => trim($parsed['english_name']),
                    'scientific_name' => trim($parsed['scientific_name'] ?? ''),
                    'confidence'      => min(0.99, max(0.50, $confidence)),
                    'visual_features' => array_values(array_filter((array) ($parsed['visual_features'] ?? []))),
                    'reasoning'       => $parsed['reasoning'] ?? null,
                ],
                'raw_text'       => $res['raw_text'],
                'latency_ms'     => $res['latency_ms'],
                'error'          => null,
            ];
        }

        return [
            'status'         => 'no_match',
            'matched'        => false,
            'model'          => $res['model'],
            'identification' => null,
            'raw_text'       => $res['raw_text'],
            'latency_ms'     => $res['latency_ms'],
            'reasoning'      => $parsed['reasoning'] ?? 'Visible morphological traits do not match any curated Kiribati trait profiles.',
            'error'          => null,
        ];
    }

    /**
     * Stage 3: Broad Global Marine Taxonomy & Web Knowledge Vision.
     * Evaluates the specimen against full global fish taxonomy knowledge when local DB/traits fail.
     *
     * @param string $imageUrlOrDataUrl Public image URL or base64 data URL
     * @param array  $samEvidence       SAM 3.1 segmentation evidence
     * @param string $hint              Fisher hint text
     * @param array  $options           Optional parameter overrides
     * @return array Standardized result with identification or error
     */
    public function identifyFromWeb(
        string $imageUrlOrDataUrl,
        array $samEvidence = [],
        string $hint = '',
        array $options = []
    ): array {
        $samSummary = '';
        if (!empty($samEvidence['status']) && $samEvidence['status'] === 'ok') {
            $count = (int) ($samEvidence['object_count'] ?? 0);
            $samSummary = "Image segmentation evidence: SAM 3.1 confirmed {$count} fish object(s).\n";
        }

        $hintLine = $hint ? "Fisher observation hint: \"{$hint}\".\n" : '';

        $prompt = <<<PROMPT
You are an expert global marine biologist and taxonomist.
STAGE 3 — BROAD GLOBAL FISH TAXONOMY & WEB KNOWLEDGE VISION:
This fish specimen did not match local reference photo catalog (Stage 1) or local curated morphological trait profiles (Stage 2).
Examine this fish photograph carefully using your comprehensive global marine taxonomy and web-scale ichthyological knowledge across Indo-Pacific, Pacific Ocean, and tropical waters.

{$samSummary}{$hintLine}
Determine:
1. The most accurate English common name and binomial scientific name for this marine fish.
2. The authentic Kiribati vernacular name if known (or best Pacific vernacular name).
3. Observed morphological characteristics visible in the photograph.
4. Estimated ciguatera or marine toxin risk level ("low", "medium", "high", "extreme") based on species biology and trophic level.
5. Concise taxonomic reasoning explaining your determination.

Return a strict JSON object:
{
  "kiribati_name": "<authentic Kiribati local name or Pacific vernacular>",
  "english_name": "<English common name>",
  "scientific_name": "<Binomial scientific name>",
  "confidence": <decimal between 0.50 and 0.99, e.g. 0.85>,
  "ciguatera_risk": "<low|medium|high|extreme>",
  "visual_features": [
    "<observed morphological trait 1>",
    "<observed morphological trait 2>",
    "<observed morphological trait 3>"
  ],
  "reasoning": "<concise explanation of species identification from global web-scale taxonomy knowledge>"
}

Rules:
- Output ONLY the JSON object. Do not include markdown codeblocks or conversational text.
PROMPT;

        $res = $this->sendVisionRequest($imageUrlOrDataUrl, $prompt, $options);
        if ($res['status'] !== 'completed') {
            return [
                'status'         => $res['status'],
                'model'          => $res['model'],
                'identification' => null,
                'raw_text'       => $res['raw_text'] ?? null,
                'latency_ms'     => $res['latency_ms'],
                'error'          => $res['error'],
            ];
        }

        $parsed = $res['parsed'];
        if ($parsed && !empty($parsed['english_name'])) {
            $confidence = (isset($parsed['confidence']) && is_numeric($parsed['confidence']))
                ? round(max(0.0, min(1.0, (float) $parsed['confidence'])), 2)
                : null;
            $risk = strtolower(trim((string) ($parsed['ciguatera_risk'] ?? 'low')));
            if (!in_array($risk, ['low', 'medium', 'high', 'extreme', 'unknown'])) {
                $risk = 'low';
            }

            return [
                'status'         => 'completed',
                'model'          => $res['model'],
                'identification' => [
                    'kiribati_name'   => trim($parsed['kiribati_name'] ?? $parsed['english_name']),
                    'english_name'    => trim($parsed['english_name']),
                    'scientific_name' => trim($parsed['scientific_name'] ?? ''),
                    'confidence'      => $confidence,
                    'ciguatera_risk'  => $risk,
                    'visual_features' => array_values(array_filter((array) ($parsed['visual_features'] ?? []))),
                    'reasoning'       => $parsed['reasoning'] ?? null,
                ],
                'raw_text'       => $res['raw_text'],
                'latency_ms'     => $res['latency_ms'],
                'error'          => null,
            ];
        }

        return [
            'status'         => 'partial',
            'model'          => $res['model'],
            'identification' => null,
            'raw_text'       => $res['raw_text'],
            'latency_ms'     => $res['latency_ms'],
            'error'          => 'Model response could not be parsed into valid species JSON.',
        ];
    }

    /**
     * Identify a fish species from a photograph using Meta Muse Glimmer multimodal vision.
     *
     * @param string $imageUrlOrDataUrl Public image URL or base64 data URL
     * @param array  $referenceSpecies  Known reference species records from Laravel DB / KB
     * @param array  $samEvidence       SAM 3.1 segmentation evidence
     * @param string $hint              Fisher hint text
     * @param array  $options           Optional parameter overrides
     * @return array Standardized vision identification result
     */
    public function identifyFishVision(
        string $imageUrlOrDataUrl,
        array $referenceSpecies = [],
        array $samEvidence = [],
        string $hint = '',
        array $options = []
    ): array {
        $targetModel = $options['model'] ?? $this->visionModel;

        if (!$this->isConfigured()) {
            return [
                'status'         => 'unconfigured',
                'model'          => $targetModel,
                'identification' => null,
                'latency_ms'     => 0,
                'error'          => 'MODEL_API_KEY is not configured in the server environment.',
            ];
        }

        // Grounding reference list from Laravel backend species
        $speciesContext = '';
        if (!empty($referenceSpecies)) {
            $formatted = [];
            foreach ($referenceSpecies as $spec) {
                $kName = $spec['kiribati_name'] ?? '';
                $eName = $spec['english_name'] ?? '';
                $sName = $spec['scientific_name'] ?? '';
                if ($kName && $eName) {
                    $formatted[] = "- {$kName} ({$eName} / {$sName})";
                }
            }
            if (!empty($formatted)) {
                $speciesContext = "Official Kiribati fisheries reference species:\n" . implode("\n", array_slice($formatted, 0, 15)) . "\n\n";
            }
        }

        $samSummary = '';
        if (!empty($samEvidence['status'])) {
            $status = $samEvidence['status'];
            if ($status === 'ok') {
                $count  = (int) ($samEvidence['object_count'] ?? 0);
                $hasBbx = !empty($samEvidence['best_box']);
                $samSummary = "Image evidence: SAM 3.1 segmented {$count} object(s) matching concept 'fish'."
                    . ($hasBbx ? ' Largest bounding box confirmed present.' : '') . "\n";
            }
        }

        $hintLine = $hint ? "Fisher observation hint: \"{$hint}\".\n" : '';

        $prompt = <<<PROMPT
You are an expert marine biologist and fisheries identification assistant for Kiribati (Central Pacific).
Examine this fish specimen photograph carefully.

{$speciesContext}{$samSummary}{$hintLine}Tasks:
1. Examine visible morphological traits: body profile, coloration, fin shapes (dorsal, caudal/tail, pectoral), mouth and jaw structure, and scale patterns.
2. Determine the most accurate fish species match, matching one of the Kiribati reference species above if applicable.
3. Return your determination as a strict JSON object with EXACTLY this structure:
{
  "kiribati_name": "<authentic Kiribati local name, e.g. Te Bwere>",
  "english_name": "<English common name, e.g. Paddletail Snapper>",
  "scientific_name": "<Binomial scientific name, e.g. Lutjanus gibbus>",
  "confidence": <decimal between 0.50 and 0.99, e.g. 0.94>,
  "visual_features": [
    "<observed morphological trait 1>",
    "<observed morphological trait 2>",
    "<observed morphological trait 3>"
  ],
  "reasoning": "<concise explanation of why this specimen matches this species>"
}

Rules:
- Do NOT suggest alternative species names outside marine taxonomy.
- Ground your identification in the provided Kiribati fisheries species list when applicable.
- Output ONLY the JSON object. Do not include markdown code block ticks or any conversational text.
PROMPT;

        $res = $this->sendVisionRequest($imageUrlOrDataUrl, $prompt, $options);
        if ($res['status'] !== 'completed') {
            return [
                'status'         => $res['status'],
                'model'          => $res['model'],
                'identification' => null,
                'latency_ms'     => $res['latency_ms'],
                'status_code'    => $res['status_code'] ?? null,
                'error'          => $res['error'],
            ];
        }

        $parsed = $res['parsed'];
        if ($parsed && !empty($parsed['kiribati_name']) && !empty($parsed['english_name'])) {
            return [
                'status'         => 'completed',
                'model'          => $res['model'],
                'identification' => [
                    'kiribati_name'   => trim($parsed['kiribati_name']),
                    'english_name'    => trim($parsed['english_name']),
                    'scientific_name' => trim($parsed['scientific_name'] ?? ''),
                    'confidence'      => (isset($parsed['confidence']) && is_numeric($parsed['confidence']))
                        ? round(max(0.0, min(1.0, (float) $parsed['confidence'])), 2)
                        : null,
                    'visual_features' => array_values(array_filter((array) ($parsed['visual_features'] ?? []))),
                    'reasoning'       => $parsed['reasoning'] ?? null,
                ],
                'raw_text'       => $res['raw_text'],
                'latency_ms'     => $res['latency_ms'],
                'error'          => null,
            ];
        }

        return [
            'status'         => 'partial',
            'model'          => $res['model'],
            'identification' => null,
            'raw_text'       => $res['raw_text'],
            'latency_ms'     => $res['latency_ms'],
            'error'          => 'Model response could not be parsed into valid species JSON.',
        ];
    }


    /**
     * Parse assistant text output from various response formats
     */
    protected function extractTextFromResponse(array $data): string
    {
        // 1. Meta / OpenAI Responses API structure: output -> content -> text
        if (!empty($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $out) {
                if (isset($out['content']) && is_array($out['content'])) {
                    foreach ($out['content'] as $c) {
                        if (isset($c['text'])) {
                            return trim($c['text']);
                        }
                    }
                }
                if (isset($out['text'])) {
                    return trim($out['text']);
                }
            }
        }

        // 2. Chat completions fallback: choices[0].message.content
        if (!empty($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }

        // 3. Direct response or text field
        if (!empty($data['response']) && is_string($data['response'])) {
            return trim($data['response']);
        }

        if (!empty($data['text']) && is_string($data['text'])) {
            return trim($data['text']);
        }

        // 4. Default JSON stringification of data if structure unrecognized
        return json_encode($data);
    }

    /**
     * Extract human-readable error message without leaking sensitive data
     */
    protected function extractErrorMessage(?array $json, string $rawBody, int $statusCode): string
    {
        if (!empty($json['error'])) {
            if (is_string($json['error'])) {
                return $json['error'];
            }
            if (is_array($json['error']) && !empty($json['error']['message'])) {
                return $json['error']['message'];
            }
        }

        if (!empty($json['message'])) {
            return $json['message'];
        }

        return "HTTP request failed with status {$statusCode}.";
    }

    /**
     * Clean and parse JSON from model output text.
     */
    protected function parseJsonPayload(string $text): ?array
    {
        $text = trim($text);

        // Strip markdown codeblocks ```json ... ``` or ``` ... ```
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $text = trim($matches[1]);
        }

        // Direct json decode
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Find outermost JSON object {...}
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $sub = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($sub, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
