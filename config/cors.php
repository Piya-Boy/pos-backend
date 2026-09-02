<?php

// CORS: restrict API to the Flutter frontend origin(s) (security.md §2.7).
// POS_FRONTEND_ORIGIN accepts a comma-separated list (apex, www, staging).
// Unset => '*' for local dev only; ALWAYS set it in prod.
$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('POS_FRONTEND_ORIGIN', '')),
)));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins !== [] ? $origins : ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
