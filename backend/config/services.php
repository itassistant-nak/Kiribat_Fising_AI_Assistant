<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'meta_ai' => [
        'endpoint'        => env('META_AI_ENDPOINT', 'https://api.meta.ai/v1/responses'),
        // Separate endpoint for vision/multimodal calls — defaults to same as endpoint.
        // Set META_AI_VISION_ENDPOINT=https://api.meta.ai/v1/chat/completions if vision
        // model uses Chat Completions format instead of Responses API format.
        'vision_endpoint' => env('META_AI_VISION_ENDPOINT', env('META_AI_ENDPOINT', 'https://api.meta.ai/v1/responses')),
        'model'           => env('META_AI_MODEL', 'muse-wonder-1.0'),
        'api_key'         => env('MODEL_API_KEY'),
        'timeout'         => (int) env('META_AI_TIMEOUT', 15),
        // Muse Spark 1.3 — vision-capable model for fish species identification
        'vision_model'    => env('META_AI_VISION_MODEL', 'muse-spark-1.3'),
        'vision_timeout'  => (int) env('META_AI_VISION_TIMEOUT', 30),
        // SAM 3.1 image segmentation on the same Meta Model API (OpenAI-compatible)
        'base_url'        => env('META_AI_BASE_URL', 'https://api.meta.ai/v1'),
        'sam_model'       => env('META_AI_SAM_MODEL', 'sam-3.1'),
        'sam_timeout'     => (int) env('META_AI_SAM_TIMEOUT', 60),
    ],

    // ── OpenAI Vision API — Stage 4 Ultimate Fallback ──────────────────────
    // Used ONLY when all 3 Meta Muse Glimmer stages AND local species-matching
    // yield no result. Key stays backend-only; never exposed to clients or Git.
    'openai' => [
        'endpoint'       => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        'vision_model'   => env('OPENAI_VISION_MODEL', 'gpt-4o'),
        'api_key'        => env('OPENAI_API_KEY'),
        'timeout'        => (int) env('OPENAI_TIMEOUT', 30),
        'vision_enabled' => (bool) env('OPENAI_VISION_ENABLED', true),
    ],

];
