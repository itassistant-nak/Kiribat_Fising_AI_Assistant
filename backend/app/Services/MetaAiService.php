<?php

namespace App\Services;

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
    protected string $endpoint;
    protected string $model;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct(
        ?string $endpoint = null,
        ?string $model = null,
        ?string $apiKey = null,
        ?int $timeout = null
    ) {
        $this->endpoint = $endpoint ?? config('services.meta_ai.endpoint', 'https://api.meta.ai/v1/responses');
        $this->model = $model ?? config('services.meta_ai.model', 'muse-wonder-1.0');
        $this->apiKey = $apiKey ?? config('services.meta_ai.api_key');
        $this->timeout = $timeout ?? (int) config('services.meta_ai.timeout', 15);
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

            $response = Http::timeout($this->timeout)
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
            // Attempt standard OpenAI-compatible multi-modal structure
            $payload = [
                'model' => $this->model,
                'input' => [
                    [
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text',      'text'      => 'Briefly describe this marine specimen.'],
                            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                        ],
                    ],
                ],
            ];

            $response = Http::timeout($this->timeout)
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
}
