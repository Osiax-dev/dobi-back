<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    // Front React (local + Vercel). Plusieurs origines possibles, séparées par des virgules.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:5173'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 3600,
    // Authentification par token Bearer : pas de cookies cross-domain.
    'supports_credentials' => false,
];
