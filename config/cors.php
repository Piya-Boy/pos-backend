<?php

// CORS: restrict API to the Flutter frontend origin (security.md §2.7).
// POS_FRONTEND_ORIGIN unset => '*' for local dev only; set it in prod.
$origin = env('POS_FRONTEND_ORIGIN', '');

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origin !== '' ? [$origin] : ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
