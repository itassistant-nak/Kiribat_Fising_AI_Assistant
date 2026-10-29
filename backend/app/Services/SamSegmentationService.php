<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SAM 3.1 image segmentation via the Meta Model API (OpenAI-compatible
 * Responses API — see meta-model-cookbook 07_sam/01_api_basics).
 *
 * Wire format (verified against Meta's official cookbook + parser docs):
 *   POST {base_url}/responses   model=sam-3.1, stream=true
 *   input: [{type:message, role:user, content:[
 *     {type:input_text, text:<concept>},
 *     {type:input_image, image_url:<data URL> } ]}]
 * The streamed output-text is a SAM 3 grammar: one line per frame,
 * `<Nf>` then comma-separated `id<|box;...|><|mask;...|>` records. We
 * decode boxes and mask PRESENCE from that grammar and ALWAYS return the
 * raw output verbatim (callers store it as evidence). Mask payloads are an
 * arithmetic-coded format (base-85, context-modelled) — coverage decoding
 * requires porting Meta's `meta_sam_parser` codec and is intentionally not
 * attempted here; coverage is reported as unknown (null), never guessed.
 *
 * SAM confirms and isolates fish; it does NOT name species. Species
 * suggestions stay with the morphological knowledge base + human
 * confirmation. The key is encapsulated here, never returned to clients.
 */
class SamSegmentationService
{
    protected string $baseUrl;
    protected string $model;
    protected ?string $apiKey;
    protected int $timeout;
    protected int $maxRawBytes = 2097152; // 2 MB accumulated-output cap

    public function __construct(
        ?string $baseUrl = null,
        ?string $model = null,
        ?string $apiKey = null,
        ?int $timeout = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?? config('services.meta_ai.base_url', 'https://api.meta.ai/v1'), '/');
        $this->model = $model ?? config('services.meta_ai.sam_model', 'sam-3.1');
        $this->apiKey = $apiKey ?? config('services.meta_ai.api_key');
        $this->timeout = $timeout ?? (int) config('services.meta_ai.sam_timeout', 60);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Segment a concept (default "fish") in an image data URL.
     *
     * @return array{status:string,model:string,concept:string,fish_detected:bool,
     *               objects:array,object_count:int,best_box:?array,mask_coverage_max:?float,
     *               raw_output:?string,raw_bytes:int,http_status:?int,latency_ms:int,error:?string}
     */
    public function segment(string $imageDataUrl, string $concept = 'fish'): array
    {
        $started = microtime(true);
        $base = [
            'model' => $this->model,
            'concept' => $concept,
            'fish_detected' => false,
            'objects' => [],
            'object_count' => 0,
            'best_box' => null,
            'mask_coverage_max' => null,
            'raw_output' => null,
            'raw_bytes' => 0,
            'http_status' => null,
        ];

        if (!$this->isConfigured()) {
            return array_merge($base, [
                'status' => 'unconfigured',
                'error' => 'MODEL_API_KEY is not configured in the server environment.',
                'latency_ms' => 0,
            ]);
        }

        if (!str_starts_with($imageDataUrl, 'data:image/')) {
            return array_merge($base, [
                'status' => 'invalid_input',
                'error' => 'SAM 3.1 requires an image data URL (data:image/...).',
                'latency_ms' => 0,
            ]);
        }

        $payload = [
            'model' => $this->model,
            'stream' => true,
            'input' => [[
                'type' => 'message',
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $concept],
                    ['type' => 'input_image', 'image_url' => $imageDataUrl],
                ],
            ]],
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders(['Accept' => 'text/event-stream'])
                ->timeout($this->timeout)
                ->withOptions(['stream' => true])
                ->post($this->baseUrl . '/responses', $payload);

            $httpStatus = $response->status();
            $body = (string) $response->getBody();
            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if (!$response->successful()) {
                Log::warning('SamSegmentationService: non-200 response', [
                    'model' => $this->model,
                    'status_code' => $httpStatus,
                    'latency_ms' => $latencyMs,
                ]);

                return array_merge($base, [
                    'status' => $this->mapErrorStatus($response, $httpStatus),
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'error' => $this->extractError($response),
                ]);
            }

            [$rawText, $timedOut] = $this->extractOutputText($body, $started);
            $decoded = $this->decodeGrammar($rawText);
            $objects = $decoded['objects'];
            $coverages = array_filter(array_column($objects, 'mask_coverage'), fn ($v) => $v !== null);

            return array_merge($base, [
                'status' => $timedOut ? 'timeout' : 'ok',
                'fish_detected' => count($objects) > 0,
                'objects' => $objects,
                'object_count' => count($objects),
                'best_box' => $decoded['best_box'],
                'mask_coverage_max' => $coverages ? max($coverages) : null,
                'raw_output' => $rawText,
                'raw_bytes' => strlen($rawText),
                'http_status' => $httpStatus,
                'latency_ms' => $latencyMs,
                'error' => $timedOut ? 'Stream exceeded the configured timeout; partial output kept.' : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('SamSegmentationService: request exception', [
                'model' => $this->model,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'status' => 'failed',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => 'SAM 3.1 request failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Accumulate output text from a Responses result. Supports SSE streams
     * (data: JSON events) and plain JSON bodies (offline fixtures).
     *
     * @return array{0:string,1:bool} [accumulatedText, timedOut]
     */
    protected function extractOutputText(string $body, float $started): array
    {
        $trimmed = ltrim($body);

        // Plain JSON (non-streaming Responses object)
        if (str_starts_with($trimmed, '{')) {
            $json = json_decode($trimmed, true);
            if (is_array($json)) {
                $text = '';
                foreach ($json['output'] ?? [] as $item) {
                    foreach ($item['content'] ?? [] as $content) {
                        if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                            $text .= $content['text'];
                        }
                    }
                }
                return [$text, false];
            }
        }

        // SSE stream: parse data: lines, join output_text deltas per lane
        $text = '';
        $timedOut = false;
        foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            $event = json_decode($data, true);
            if (!is_array($event)) {
                continue;
            }
            $type = $event['type'] ?? '';
            if ($type === 'response.output_text.delta' && isset($event['delta']) && is_string($event['delta'])) {
                $text .= $event['delta'];
            } elseif ($type === 'response.content_part.done'
                && (($event['part']['type'] ?? '') === 'output_text')
                && isset($event['part']['text']) && is_string($event['part']['text'])
                && $text === '') {
                // Some emitters finalize without deltas
                $text = $event['part']['text'];
            } elseif ($type === 'response.output_text.done' && isset($event['text']) && is_string($event['text'])) {
                if ($text === '') {
                    $text = $event['text'];
                }
            }

            if (strlen($text) > $this->maxRawBytes) {
                $text = substr($text, 0, $this->maxRawBytes);
                $timedOut = false;
                break;
            }
            if ((microtime(true) - $started) > $this->timeout) {
                $timedOut = true;
                break;
            }
        }

        return [$text, $timedOut];
    }

    /**
     * Decode the SAM 3 image grammar into box/mask records.
     * Format: `<Nf>` prefix, records `id<|box;...|><|mask;...|>` per line.
     */
    protected function decodeGrammar(string $rawText): array
    {
        $objects = [];
        if ($rawText === '') {
            return ['objects' => [], 'best_box' => null];
        }

        preg_match_all(
            '/(\d+)<\|box;x1=(\d+);y1=(\d+);x2=(\d+);y2=(\d+);w=(\d+);h=(\d+)\|>/',
            $rawText,
            $boxes,
            PREG_OFFSET_CAPTURE
        );
        preg_match_all(
            '/<\|mask;x=(\d+);y=(\d+);data=([^\|]+)\|>/',
            $rawText,
            $masks,
            PREG_OFFSET_CAPTURE
        );

        // Pair each mask with the nearest preceding box (document order)
        $maskByBoxOffset = [];
        foreach ($masks[0] as $i => $full) {
            $offset = $full[1];
            $owner = null;
            foreach ($boxes[0] as $j => $boxFull) {
                if ($boxFull[1] < $offset) {
                    $owner = $j;
                } else {
                    break;
                }
            }
            if ($owner !== null) {
                $maskByBoxOffset[$owner] = $masks[3][$i][0]; // RLE data
            }
        }

        $bestBox = null;
        $bestArea = -1;
        foreach ($boxes[0] as $j => $full) {
            $id = $boxes[1][$j][0];
            $x1 = (int) $boxes[2][$j][0];
            $y1 = (int) $boxes[3][$j][0];
            $x2 = (int) $boxes[4][$j][0];
            $y2 = (int) $boxes[5][$j][0];
            $w = max(1, (int) $boxes[6][$j][0]);
            $h = max(1, (int) $boxes[7][$j][0]);

            $maskData = $maskByBoxOffset[$j] ?? null;

            $box = [
                'id' => $id,
                'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2,
                'w' => $w, 'h' => $h,
                'has_mask' => $maskData !== null,
                // Arithmetic-coded payload; coverage unknown until the
                // official codec is ported (see class docblock).
                'mask_coverage' => null,
            ];
            $objects[] = $box;

            $area = ($x2 - $x1) * ($y2 - $y1);
            if ($area > $bestArea) {
                $bestArea = $area;
                $bestBox = $box;
            }
        }

        return ['objects' => $objects, 'best_box' => $bestBox];
    }

    protected function mapErrorStatus($response, int $httpStatus): string
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return 'unauthorized';
        }
        if ($httpStatus === 404) {
            $message = strtolower($this->extractError($response));
            return str_contains($message, 'model') ? 'model_unavailable' : 'not_found';
        }
        return 'failed';
    }

    protected function extractError($response): string
    {
        $json = $response->json();
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
        // Never include raw bodies that could echo request data
        return 'SAM 3.1 request failed with HTTP ' . $response->status() . '.';
    }
}
