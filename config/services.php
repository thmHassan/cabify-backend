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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'barikoi' => [
        'api_key' => env('BARIKOI_API_KEY', env('BARIKOI_KEY')),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'key' => env('STRIPE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'mapify' => [
        'base_url' => env('MAPIFY_BASE_URL', 'https://client.mapifyit.com'),
        'api_token' => env('MAPIFY_API_TOKEN'),
    ],

    'node_socket' => [
        'url' => env('NODE_SOCKET_URL', 'http://127.0.0.1:8000/socket-api'),
        'internal_secret' => env('NODE_INTERNAL_SECRET'),
    ],

];
