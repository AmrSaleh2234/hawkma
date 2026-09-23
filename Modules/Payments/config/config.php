<?php

return [
    'driver' => env('PAYMENT_GATEWAY', 'fake'),

    'currency' => 'SAR',

    'callback_url' => env('PAYMENT_CALLBACK_URL', env('CLIENT_FRONTEND_URL', 'http://localhost:3001').'/bookings/payment-callback'),

    'moyasar' => [
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        'base_url' => 'https://api.moyasar.com/v1',
    ],
];
