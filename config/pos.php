<?php

return [
    'spreadsheet_id' => env('GOOGLE_SPREADSHEET_ID', ''),
    'sa_key_path' => env('GOOGLE_SA_KEY_PATH', storage_path('google/service-account.json')),
    'sa_key_json' => env('GOOGLE_SA_KEY_JSON', ''),
    'auth_ttl' => (int) env('POS_AUTH_TTL_SECONDS', 21600),
    'initial_pin' => env('POS_INITIAL_PIN', 'zaq1234'),
    'auth_salt' => env('POS_AUTH_SALT', ''),
    'order_base_url' => env('POS_ORDER_BASE_URL', ''),      // Flutter customer URL for table QR (Task 9)
    'frontend_origin' => env('POS_FRONTEND_ORIGIN', ''),     // CORS allowed origin (Task 10)
    'lock_ms' => ['order' => 15000, 'payment' => 15000, 'call' => 5000, 'settings' => 10000],
    'cache_ttl' => ['catalog' => 120, 'settings' => 120],
    'payment_methods' => ['CASH', 'TRANSFER', 'CARD', 'OTHER'],
    'roles' => ['ADMIN', 'KITCHEN', 'STAFF', 'CASHIER'],
];
