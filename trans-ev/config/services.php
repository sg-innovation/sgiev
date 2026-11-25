<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    // Map tiles for the station finder. OpenFreeMap needs no key; switch to a paid provider for heavy production use.
    'maps' => [
        'provider' => env('MAP_PROVIDER', 'openfreemap'),
        'style' => env('MAP_STYLE_URL', 'https://tiles.openfreemap.org/styles/liberty'),
        'google_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // Which provider sends SMS (msg91 | smsbirds) and WhatsApp (meta | sgiconnects). Chosen in Settings → Integrations.
    'sms' => ['provider' => env('SMS_PROVIDER', 'msg91')],

    'smsbirds' => [
        'url' => env('SMSBIRDS_URL', 'http://smsbirds.in/sms-panel/api/http/index.php'),
        'username' => env('SMSBIRDS_USERNAME'),
        'api_key' => env('SMSBIRDS_API_KEY'),
        'sender' => env('SMSBIRDS_SENDER'),
        'route' => env('SMSBIRDS_ROUTE', 'TRANS'),
    ],

    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        'sender_id' => env('MSG91_SENDER_ID'),
        'otp_template_id' => env('MSG91_OTP_TEMPLATE_ID'),
        'booking_template_id' => env('MSG91_BOOKING_TEMPLATE_ID'),
    ],

    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    ],

    'sgiconnects' => [
        'base_url' => env('SGICONNECTS_BASE_URL', 'https://sgiconnects.com/api'),
        'license' => env('SGICONNECTS_LICENSE'),
        'api_key' => env('SGICONNECTS_API_KEY'),
    ],

    'whatsapp_provider' => env('WHATSAPP_PROVIDER', 'meta'),

    // Ticket emails carry Reply-To support+TKT-000123@… ; the mail provider posts incoming replies to
    // /webhooks/inbound-email/{token}. Set in Settings → Integrations → Support email.
    'support_inbound' => [
        'address' => env('SUPPORT_INBOUND_ADDRESS'),
        'token' => env('SUPPORT_INBOUND_TOKEN'),
    ],

    // Phase F: OCPP central system (`php artisan ocpp:serve`). Chargers connect to public_url/<identity>.
    'auth_test' => ['phone' => env('AUTH_TEST_PHONE'), 'code' => env('AUTH_TEST_OTP')],

    'ocpp' => [
        'host' => env('OCPP_HOST', '0.0.0.0'),
        'port' => (int) env('OCPP_PORT', 9000),
        'public_url' => env('OCPP_PUBLIC_URL', 'ws://127.0.0.1:9000/ocpp'),
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'service_account' => env('FCM_SERVICE_ACCOUNT'),
    ],

];
