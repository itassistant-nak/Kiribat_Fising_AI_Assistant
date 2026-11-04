<?php

namespace App\Services;

use App\Services\Concerns\ResolvesApiEndpoints;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Vision API Service — Ultimate Fallback Stage 4
 *
 * Sends fish images to OpenAI's gpt-4o vision model as the final fallback
 * after the 3-stage Meta Muse Glimmer pipeline and local species-matching
 * have been exhausted.
 *
 * Security: OPENAI_API_KEY is loaded exclusively from backend config,
 * never logged in full, and never exposed to API responses or Git.
 * ADR-002: all AI output is strictly advisory; human confirmation required.
 */
class OpenAiVisionService
{
    use ResolvesApiEndpoints;
    protected string $endpoint;
    protected string $visionModel;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct()
    {
        $this->endpoint    = config('services.openai.endpoint', 'https://api.openai.com/v1/chat/completions');
        $this->visionModel = config('services.openai.vision_model', 'gpt-4o');
        $this->apiKey      = config('services.openai.api_key') ?: null;
        $this->timeout     = (int) config('services.openai.timeout', 30);
    }

    /**
     * Returns true when an API key is present. When false, no HTTP call is made.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Returns the vision model name (e.g. "gpt-4o").
     */
    public function getModel(): string
    {
        return $this->visionModel;
    }

    /**
     * Identify a fish from an image using OpenAI's gpt-4o vision model.
     *
     * @param  string  $imageUrlOrDataUrl  Full https:// URL or data:image/jpeg;base64,... string
     * @param  array   $referenceSpecies   Optional list of known Kiribati species to ground the prompt
     * @param  string  $hint               Optional text hint (Kiribati or English species name)
     * @return array{status:string, model:string, identification:array|null, raw_text:string|null, latency_ms:int, error:string|null}
     */
    public function identifyFishVision(
        string $imageUrlOrDataUrl,
        array  $referenceSpecies = [],
        string $hint = ''
    ): array {
        if (!$this->isConfigured()) {
            return [
                'status'         => 'unconfigured',
                'model'          => $this->visionModel,
                'identification' => null,
                'raw_text'       => null,
                'latency_ms'     => 0,
                'error'          => 'OPENAI_API_KEY is not configured',
            ];
        }

        $start = hrtime(true);

        try {
            $prompt = $this->buildPrompt($referenceSpecies, $hint);

            $response = Http::withOptions($this->getCurlOptions($this->endpoint))
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->endpoint, [
                'model'           => $this->visionModel,
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
                'max_tokens' => 1024,
            ]);

            $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

            if (!$response->successful()) {
                Log::warning('[OpenAiVisionService] API error', [
                    'status' => $response->status(),
                    'body'   => substr((string) $response->body(), 0, 500),
                ]);
                return [
                    'status'         => 'failed',
                    'model'          => $this->visionModel,
                    'identification' => null,
                    'raw_text'       => null,
                    'latency_ms'     => $latencyMs,
                    'error'          => "OpenAI API HTTP {$response->status()}",
                ];
            }

            $rawText = $response->json('choices.0.message.content', '');
            $identification = $this->parseIdentification($rawText);

            if ($identification === null) {
                return [
                    'status'         => 'no_match',
                    'model'          => $this->visionModel,
                    'identification' => null,
                    'raw_text'       => $rawText,
                    'latency_ms'     => $latencyMs,
                    'error'          => 'Could not parse structured identification from OpenAI response',
                ];
            }

            return [
                'status'         => 'completed',
                'model'          => $this->visionModel,
                'identification' => $identification,
                'raw_text'       => $rawText,
                'latency_ms'     => $latencyMs,
                'error'          => null,
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);
            Log::error('[OpenAiVisionService] Exception', [
                'message' => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return [
                'status'         => 'failed',
                'model'          => $this->visionModel,
                'identification' => null,
                'raw_text'       => null,
                'latency_ms'     => $latencyMs,
                'error'          => $e->getMessage(),
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the system prompt grounded with known Kiribati species.
     */
    protected function buildPrompt(array $referenceSpecies, string $hint): string
    {
        $speciesContext = '';
        if (!empty($referenceSpecies)) {
            $lines = [];
            foreach (array_slice($referenceSpecies, 0, 15) as $s) {
                $kiribati  = $s['kiribati_name']  ?? '';
                $english   = $s['english_name']   ?? '';
                $scientific = $s['scientific_name'] ?? '';
                if ($kiribati || $english) {
                    $lines[] = "- {$kiribati} ({$english}) [{$scientific}]";
                }
            }
            if ($lines) {
                $speciesContext = "\n\nKnown Kiribati marine species for reference:\n" . implode("\n", $lines);
            }
        }

        $hintLine = $hint ? "\nFisher hint: \"{$hint}\"" : '';

        return <<<PROMPT
You are a marine species identification expert specializing in Pacific Island fisheries, particularly Kiribati reef and pelagic fish.

Analyze this fish photograph and identify the species with the highest confidence possible. Return ONLY a valid JSON object with this exact structure:

{
  "kiribati_name": "Te Bwere",
  "english_name": "Paddletail Snapper",
  "scientific_name": "Lutjanus gibbus",
  "confidence": 0.88,
  "ciguatera_risk": "high",
  "visual_features": ["reddish-pink body", "rounded caudal fin", "steep forehead"],
  "reasoning": "One sentence explaining the key visual evidence for identification."
}

Rules:
- confidence must be a decimal between 0.0 and 1.0
- ciguatera_risk must be one of: "low", "medium", "high", "extreme"
- visual_features should list 2–4 distinct morphological traits observed
- Do NOT invent species not visible in the image
- If the fish is not clearly identifiable, return your best guess with a low confidence (< 0.5)
- Never mention ciguatera toxicity as visually detectable — it cannot be seen in photographs{$speciesContext}{$hintLine}
PROMPT;
    }

    /**
     * Parse raw JSON text from the OpenAI response into a standardized identification array.
     * Returns null when the response cannot be parsed or required fields are missing.
     */
    protected function parseIdentification(?string $rawText): ?array
    {
        if (empty($rawText)) {
            return null;
        }

        // Strip markdown fences if present
        $cleaned = trim($rawText);
        $cleaned = preg_replace('/^```json\s*/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        $data = json_decode($cleaned, true);
        if (!is_array($data)) {
            return null;
        }

        // Validate required fields
        $kiribatiName  = trim($data['kiribati_name']  ?? '');
        $englishName   = trim($data['english_name']   ?? '');
        $scientificName= trim($data['scientific_name']?? '');
        $confidence    = (float) ($data['confidence']  ?? 0.0);

        if (empty($kiribatiName) && empty($englishName)) {
            return null;
        }

        return [
            'kiribati_name'  => $kiribatiName  ?: 'Unknown',
            'english_name'   => $englishName   ?: 'Unknown',
            'scientific_name'=> $scientificName ?: 'Unknown',
            'confidence'     => round(max(0.0, min(1.0, $confidence)), 2),
            'ciguatera_risk' => in_array($data['ciguatera_risk'] ?? '', ['low', 'medium', 'high', 'extreme'])
                                    ? $data['ciguatera_risk']
                                    : 'low',
            'visual_features'=> (array) ($data['visual_features'] ?? []),
            'reasoning'      => $data['reasoning'] ?? null,
        ];
    }
}
