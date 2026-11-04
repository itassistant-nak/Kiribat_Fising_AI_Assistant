<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter([
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:3030',
        'http://127.0.0.1:3030',
        'http://localhost:8080',
        'http://127.0.0.1:8080',
        // Production Vercel URL — set FRONTEND_URL in Railway env vars
        env('FRONTEND_URL'),
    ]),
    'allowed_origins_patterns' => [
        '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#',
        // Allow all Vercel preview deployments (*.vercel.app)
        '#^https://[a-z0-9\-]+\.vercel\.app$#',
        // Allow Railway-hosted frontends if ever needed
        '#^https://[a-z0-9\-]+\.up\.railway\.app$#',
    ],
    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => false,
];

