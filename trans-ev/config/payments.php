<?php

return [
    'sabpaisa' => [
        'base_urls' => [
            'staging' => env('SABPAISA_STAGING_URL', 'https://staging-sb-merchant-api.sabpaisa.in'),
            'production' => env('SABPAISA_PRODUCTION_URL', 'https://merchant-api.sabpaisa.in'),
        ],
        'timeout' => (int) env('SABPAISA_TIMEOUT', 20),

        /*
         * Local simulator: payments go to a built-in test checkout page instead of SabPaisa, so the full
         * booking → payment → confirmation → refund flow can be exercised without credentials.
         * Only ever active when APP_ENV=local. Never enable in production.
         */
        'simulate' => env('APP_ENV') === 'local' && filter_var(env('SABPAISA_SIMULATE', false), FILTER_VALIDATE_BOOL),
    ],

    // Hold times, refund tiers and webhook tolerance are business rules stored in the database
    // (Admin → Settings → Business rules; see App\Support\BusinessRules).
];
