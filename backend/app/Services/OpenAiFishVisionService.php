<?php

namespace App\Services;

use App\Services\Concerns\ResolvesApiEndpoints;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAiFishVisionService
 *
 * Dedicated OpenAI Vision service for independent fish image identification.
 * Interacts with OpenAI vision models (e.g. gpt-4o) using structured JSON output.
 *
 * Security & Reliability:
 * - Credentials loaded exclusively from backend config (services.openai).
 * - API key is never logged, exposed in exception traces, or returned to clients.
 * - Handles timeouts, API error codes (4xx, 5xx), invalid JSON, and unconfigured states.
 * - Never returns fabricated identification results.
 */
class OpenAiFishVisionService
{
    use ResolvesApiEndpoints;
    protected string $endpoint;
    protected string $visionModel;
    protected ?string $apiKey;
    protected int $timeout;
    protected bool $enabled;

    public function __construct(
        ?string $endpoint = null,
        ?string $visionModel = null,
        ?string $apiKey = null,
        ?int $timeout = null,
        ?bool $enabled = null
    ) {
        $this->endpoint    = $endpoint ?? config('services.openai.endpoint', 'https://api.openai.com/v1/chat/completions');
        $this->visionModel = $visionModel ?? config('services.openai.vision_model', 'gpt-4o');
        $this->apiKey      = $apiKey ?? (config('services.openai.api_key') ?: null);
        $this->timeout     = $timeout ?? (int) config('services.openai.timeout', 30);
        $this->enabled     = $enabled ?? (bool) config('services.openai.vision_enabled', true);
    }

    /**
     * Check if the OpenAI vision service has recorded an active insufficient quota state.
     */
    public function isQuotaExceeded(): bool
    {
        return (bool) Cache::get('openai_quota_exceeded', false);
    }

    /**
     * Record insufficient quota error with a 10-minute cooldown to avoid hammering the provider.
     */
    public function recordQuotaExceeded(?string $message = null): void
    {
        Cache::put('openai_quota_exceeded', $message ?? 'You have no credits remaining.', now()->addMinutes(10));
    }

    /**
     * Clear cached quota exceeded state (e.g. after adding credits).
     */
    public function clearQuotaExceeded(): void
    {
        Cache::forget('openai_quota_exceeded');
    }

    /**
     * Check if the OpenAI vision service is configured and enabled.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && !empty($this->apiKey) && is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    /**
     * Check if the OpenAI vision service is enabled by config.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured vision model identifier.
     */
    public function getModel(): string
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
     * Identify a fish photograph using OpenAI Vision.
     *
     * @param string $imageUrlOrDataUrl Image URL (http/https) or Base64 data URL (data:image/...)
     * @param array  $referenceSpecies  Grounding context: Kiribati species catalog records
     * @param string $hint              Optional user or fisher observation hint
     * @param array  $options           Optional overrides (model, temperature, etc.)
     * @return array Standardized result array
     */
    public function identifyFishVision(
        string $imageUrlOrDataUrl,
        array  $referenceSpecies = [],
        string $hint = '',
        array  $options = []
    ): array {
        $targetModel = $options['model'] ?? $this->visionModel;

        if (!$this->enabled) {
            return [
                'status'         => 'disabled',
                'provider'       => 'openai',
                'model'          => $targetModel,
                'identification' => null,
                'raw_response'   => null,
                'latency_ms'     => 0,
                'error'          => 'OPENAI_VISION_ENABLED is set to false.',
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'status'         => 'unconfigured',
                'provider'       => 'openai',
                'model'          => $targetModel,
                'identification' => null,
                'raw_response'   => null,
                'latency_ms'     => 0,
                'error'          => 'OPENAI_API_KEY is not configured in backend environment.',
            ];
        }

        if ($this->isQuotaExceeded()) {
            $cachedError = (string) Cache::get('openai_quota_exceeded', 'OpenAI account has no credits remaining (quota exceeded). Retries temporarily paused.');
            return [
                'status'         => 'quota_exceeded',
                'provider'       => 'openai',
                'model'          => $targetModel,
                'identification' => null,
                'raw_response'   => null,
                'latency_ms'     => 0,
                'error'          => $cachedError,
            ];
        }

        $startTime = hrtime(true);

        try {
            $prompt = $this->buildPrompt($referenceSpecies, $hint);

            $payload = [
                'model'           => $targetModel,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $prompt,
                            ],
                            [
                                'type'      => 'image_url',
                                'image_url' => ['url' => $imageUrlOrDataUrl],
                            ],
                        ],
                    ],
                ],
                'max_tokens' => 1200,
            ];

            if (isset($options['temperature'])) {
                $payload['temperature'] = (float) $options['temperature'];
            }

            $response = Http::withOptions($this->getCurlOptions($this->endpoint))
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->endpoint, $payload);

            $latencyMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                $rawText = $data['choices'][0]['message']['content'] ?? '';
                $parsed = $this->parseStructuredOutput($rawText);

                if ($parsed === null) {
                    Log::warning('OpenAiFishVisionService: Failed to parse structured JSON from response', [
                        'model' => $targetModel,
                        'latency_ms' => $latencyMs,
                        'preview' => substr($rawText, 0, 200),
                    ]);

                    return [
                        'status'         => 'invalid_response',
                        'provider'       => 'openai',
                        'model'          => $targetModel,
                        'identification' => null,
                        'raw_response'   => $rawText,
                        'latency_ms'     => $latencyMs,
                        'error'          => 'OpenAI returned invalid or unparseable JSON format.',
                    ];
                }

                return [
                    'status'         => 'completed',
                    'provider'       => 'openai',
                    'model'          => $data['model'] ?? $targetModel,
                    'identification' => $parsed,
                    'raw_response'   => $rawText,
                    'latency_ms'     => $latencyMs,
                    'error'          => null,
                ];
            }

            // HTTP Error handling
            $statusCode = $response->status();
            $json = $response->json();
            $errorMessage = $this->extractErrorMessage($json, $statusCode);
            $errorObj = $json['error'] ?? [];
            $errorCode = is_array($errorObj) ? ($errorObj['code'] ?? null) : null;
            $errorType = is_array($errorObj) ? ($errorObj['type'] ?? null) : null;

            $isQuota = ($statusCode === 429 && ($errorCode === 'insufficient_quota' || $errorType === 'insufficient_quota'))
                || ($statusCode === 402)
                || str_contains(strtolower($errorMessage), 'no credits remaining')
                || str_contains(strtolower($errorMessage), 'insufficient_quota')
                || str_contains(strtolower($errorMessage), 'exceeded your current quota');

            $isAuth = ($statusCode === 401)
                || ($errorCode === 'invalid_api_key')
                || str_contains(strtolower($errorMessage), 'incorrect api key');

            $isRateLimit = ($statusCode === 429 && !$isQuota);
            $isTimeout = ($statusCode === 408 || $statusCode === 504);
            $isModelUnavailable = ($statusCode === 404);

            if ($isQuota) {
                $status = 'quota_exceeded';
                $this->recordQuotaExceeded($errorMessage);
            } elseif ($isAuth) {
                $status = 'unauthorized';
            } elseif ($isRateLimit) {
                $status = 'rate_limited';
            } elseif ($isTimeout) {
                $status = 'timeout';
            } elseif ($isModelUnavailable) {
                $status = 'model_unavailable';
            } else {
                $status = 'failed';
            }

            Log::warning('OpenAiFishVisionService: Request returned non-200 status', [
                'model'       => $targetModel,
                'status_code' => $statusCode,
                'status'      => $status,
                'latency_ms'  => $latencyMs,
                'error'       => $errorMessage,
            ]);

            return [
                'status'         => $status,
                'provider'       => 'openai',
                'model'          => $targetModel,
                'identification' => null,
                'raw_response'   => null,
                'latency_ms'     => $latencyMs,
                'status_code'    => $statusCode,
                'error'          => $errorMessage,
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

            $isTimeout = str_contains(strtolower($e->getMessage()), 'timeout')
                || str_contains(strtolower($e->getMessage()), 'timed out');

            Log::error('OpenAiFishVisionService: Network or API exception', [
                'model'      => $targetModel,
                'exception'  => get_class($e),
                'error'      => $e->getMessage(),
                'latency_ms' => $latencyMs,
            ]);

            return [
                'status'         => $isTimeout ? 'timeout' : 'failed',
                'provider'       => 'openai',
                'model'          => $targetModel,
                'identification' => null,
                'raw_response'   => null,
                'latency_ms'     => $latencyMs,
                'error'          => 'Connection to OpenAI vision API failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Construct grounding prompt with Kiribati marine species catalog and instructions.
     */
    protected function buildPrompt(array $referenceSpecies, string $hint): string
    {
        $speciesContext = '';
        if (!empty($referenceSpecies)) {
            $lines = [];
            foreach (array_slice($referenceSpecies, 0, 25) as $s) {
                $kName = $s['kiribati_name'] ?? '';
                $eName = $s['english_name'] ?? ($s['common_name'] ?? '');
                $sName = $s['scientific_name'] ?? '';
                if ($kName || $eName || $sName) {
                    $lines[] = "- {$kName} ({$eName} / {$sName})";
                }
            }
            if (!empty($lines)) {
                $speciesContext = "Kiribati Marine Species Reference Catalogue Context:\n"
                    . implode("\n", $lines) . "\n\n";
            }
        }

        $hintLine = $hint !== '' ? "Fisher / observer hint: \"{$hint}\"\n\n" : '';

        return <<<PROMPT
You are a marine biology taxonomist and ichthyologist identifying fish species from central Pacific waters (Kiribati EEZ, Gilbert Islands, Phoenix Islands, Line Islands).

Examine this fish photograph carefully.
{$speciesContext}{$hintLine}Tasks:
1. Examine key morphological features: body shape, tail (caudal fin) geometry, dorsal/anal fin shapes, markings, coloration, and head/mouth profile.
2. Determine the most accurate binomial scientific name, English common name, and I-Kiribati local name where available.
3. If this species matches a record in the Kiribati reference catalogue above, use that taxonomy. If it is a different marine species, provide its accurate scientific name without inventing a fake name.
4. List visible morphological traits observed directly in the photograph.
5. Provide concise taxonomic reasoning.
6. Provide up to 3 candidate alternative species with their scientific and common names.
7. Return confidence score (decimal between 0.00 and 1.00). If uncertain or poor image quality, return confidence < 0.50.

Return ONLY a valid JSON object matching this schema:
{
  "scientific_name": "<binomial scientific name, e.g. Lutjanus gibbus>",
  "common_name": "<English common name, e.g. Paddletail Snapper>",
  "kiribati_name": "<authentic I-Kiribati local name if known or null, e.g. Te Bwere>",
  "confidence": <decimal between 0.00 and 1.00, e.g. 0.88>,
  "visible_traits": [
    "<observed morphological trait 1>",
    "<observed morphological trait 2>",
    "<observed morphological trait 3>"
  ],
  "reasoning": "<concise biological reasoning explaining visible evidence for identification>",
  "alternatives": [
    {
      "scientific_name": "<alternative scientific name>",
      "common_name": "<alternative common name>",
      "kiribati_name": "<alternative Kiribati name or null>",
      "confidence": <decimal between 0.00 and 1.00>
    }
  ],
  "review_status": "needs_review",
  "ciguatera_risk": "<low|medium|high|extreme|unknown>"
}

Strict Rules:
- Never fabricate species or return non-existent organisms.
- Never assert that fish toxicity or ciguatoxins are visually detectable from a photograph (toxin bioaccumulation cannot be seen).
- Review status must always be "needs_review" (human confirmation is mandatory).
- Output ONLY the JSON object. Do not enclose in markdown blocks.
PROMPT;
    }

    /**
     * Parse and validate structured output JSON.
     */
    protected function parseStructuredOutput(string $rawText): ?array
    {
        $text = trim($rawText);
        if ($text === '') {
            return null;
        }

        // Strip markdown fences
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        // Fallback: extract outermost JSON
        if (!is_array($decoded)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $sub = substr($text, $start, $end - $start + 1);
                $decoded = json_decode($sub, true);
            }
        }

        if (!is_array($decoded)) {
            return null;
        }

        $scientificName = trim((string) ($decoded['scientific_name'] ?? ''));
        $commonName     = trim((string) ($decoded['common_name'] ?? ($decoded['english_name'] ?? '')));
        $kiribatiName   = trim((string) ($decoded['kiribati_name'] ?? ''));
        $confidence     = isset($decoded['confidence']) ? (float) $decoded['confidence'] : 0.0;

        // At least scientific_name or common_name must be present
        if ($scientificName === '' && $commonName === '') {
            return null;
        }

        // Normalize alternatives
        $alternatives = [];
        if (!empty($decoded['alternatives']) && is_array($decoded['alternatives'])) {
            foreach ($decoded['alternatives'] as $alt) {
                if (is_array($alt)) {
                    $altSci = trim((string) ($alt['scientific_name'] ?? ''));
                    $altCom = trim((string) ($alt['common_name'] ?? ($alt['english_name'] ?? '')));
                    if ($altSci !== '' || $altCom !== '') {
                        $alternatives[] = [
                            'scientific_name' => $altSci ?: 'Unknown',
                            'common_name'     => $altCom ?: 'Unknown',
                            'kiribati_name'   => trim((string) ($alt['kiribati_name'] ?? '')) ?: null,
                            'confidence'      => isset($alt['confidence']) ? round(max(0.0, min(1.0, (float) $alt['confidence'])), 2) : 0.50,
                        ];
                    }
                }
            }
        }

        // Extract visible traits
        $visibleTraits = [];
        if (!empty($decoded['visible_traits']) && is_array($decoded['visible_traits'])) {
            $visibleTraits = array_values(array_filter(array_map('trim', $decoded['visible_traits'])));
        } elseif (!empty($decoded['visual_features']) && is_array($decoded['visual_features'])) {
            $visibleTraits = array_values(array_filter(array_map('trim', $decoded['visual_features'])));
        }

        // Toxin risk check
        $risk = strtolower(trim((string) ($decoded['ciguatera_risk'] ?? 'unknown')));
        if (!in_array($risk, ['low', 'medium', 'high', 'extreme', 'unknown'], true)) {
            $risk = 'unknown';
        }

        return [
            'scientific_name' => $scientificName ?: 'Unknown',
            'common_name'     => $commonName ?: 'Unknown',
            'kiribati_name'   => $kiribatiName ?: null,
            'confidence'      => round(max(0.0, min(1.0, $confidence)), 2),
            'visible_traits'  => $visibleTraits,
            'reasoning'       => trim((string) ($decoded['reasoning'] ?? '')),
            'alternatives'    => $alternatives,
            'review_status'   => 'needs_review',
            'ciguatera_risk'  => $risk,
        ];
    }

    /**
     * Extract human-readable error without credentials.
     */
    protected function extractErrorMessage(?array $json, int $statusCode): string
    {
        if (!empty($json['error'])) {
            if (is_string($json['error'])) {
                return $json['error'];
            }
            if (is_array($json['error']) && !empty($json['error']['message'])) {
                return $json['error']['message'];
            }
        }

        return "OpenAI API returned HTTP {$statusCode}.";
    }
}
