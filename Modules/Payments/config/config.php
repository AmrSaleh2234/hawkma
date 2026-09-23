<?php

return [
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    'callback_url' => env('PAYMENT_CALLBACK_URL', 'http://localhost:3001/bookings/payment-callback'),

    'moyasar' => [
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
    ],
];
