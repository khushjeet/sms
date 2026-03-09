<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie'
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://frontend.ipsyogapatti.com',
        'http://localhost:4000',
        'http://127.0.0.1:4000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:4200',
        'http://127.0.0.1:4200',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
