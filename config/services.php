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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'apify' => [
        'token' => env('APIFY_API_TOKEN'),
        'base_url' => env('APIFY_BASE_URL', 'https://api.apify.com/v2'),
    ],

    'openrouter' => [
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'text_model' => env('OPENROUTER_TEXT_MODEL', 'anthropic/claude-sonnet-4-5'),
        'image_model' => env('OPENROUTER_IMAGE_MODEL', 'sourceful/riverflow-v2-fast'),
    ],

    'tripay' => [
        'merchant_code' => env('TRIPAY_MERCHANT_CODE'),
        'api_key' => env('TRIPAY_API_KEY'),
        'private_key' => env('TRIPAY_PRIVATE_KEY'),
        'sandbox' => env('TRIPAY_SANDBOX', true),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'sandbox' => env('MIDTRANS_SANDBOX', true),
    ],

    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
    ],

    'duitku' => [
        'merchant_code' => env('DUITKU_MERCHANT_CODE'),
        'api_key' => env('DUITKU_API_KEY'),
        'sandbox' => env('DUITKU_SANDBOX', true),
    ],

    'doku' => [
        'client_id' => env('DOKU_CLIENT_ID'),
        'secret_key' => env('DOKU_SECRET_KEY'),
        'notification_path' => env('DOKU_NOTIFICATION_PATH', '/payment/callback/doku'),
        'sandbox' => env('DOKU_SANDBOX', true),
    ],

    'biteship' => [
        'api_key' => env('BITESHIP_API_KEY'),
        'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),
        // Area id of the store's shipping origin (warehouse/outlet), looked
        // up once via the maps/areas search endpoint. Rate quotes are
        // always FROM this fixed point.
        'origin_area_id' => env('BITESHIP_ORIGIN_AREA_ID'),
        'couriers' => env('BITESHIP_COURIERS', 'jne,jnt,sicepat,anteraja,ninja,pos,tiki'),
        // Sender identity Biteship requires on every real "create shipment"
        // call (as opposed to a free rate quote). Only needed once you go
        // beyond quoting rates.
        'sender_name' => env('BITESHIP_SENDER_NAME'),
        'sender_phone' => env('BITESHIP_SENDER_PHONE'),
        'sender_address' => env('BITESHIP_SENDER_ADDRESS'),
        // Unguessable path segment for the inbound status webhook
        // (routes/api/shipping.php). Biteship does not sign webhook
        // payloads, so this is the only thing standing between the
        // endpoint and the open internet — the handler additionally
        // re-fetches the order from Biteship's API rather than trusting
        // the payload body, but a leaked token still lets someone trigger
        // spurious refreshes. Generate with `php artisan tinker` ->
        // `Str::random(40)`, or any long random string.
        'webhook_token' => env('BITESHIP_WEBHOOK_TOKEN'),
    ],

];
