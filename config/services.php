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

    'openweather' => [
        'key' => env('OPEN_WEATHER_API_KEY'),
    ],
    'mapbox' => [
        'token' => env('MAPBOX_ACCESS_TOKEN'),
        'username' => env('MAPBOX_USERNAME'),
        'dataset_id' => env('MAPBOX_DATASET_ID'),
        'tileset_id' => env('MAPBOX_TILESET_ID'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'sender_email' => env('BREVO_SENDER_EMAIL', env('MAIL_FROM_ADDRESS')),
        'sender_name' => env('BREVO_SENDER_NAME', env('MAIL_FROM_NAME')),
        'api_url' => env('BREVO_API_URL', 'https://api.brevo.com/v3'),
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

];
