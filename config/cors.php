<?php

return [

    'paths' => [
        'api/*',
        'storage/*',
        'sanctum/csrf-cookie'
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            implode(',', array_filter([
                env('FRONTEND_URL'),
                'http://localhost:4000',
                'http://127.0.0.1:4000',
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://localhost:4200',
                'http://127.0.0.1:4200',
            ]))
        ))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
