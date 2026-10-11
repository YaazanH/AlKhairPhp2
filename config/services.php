<?php

return [

    'photon' => [
        'enabled' => env('ADDRESS_ONLINE_SUGGESTIONS', true),
        'url' => env('PHOTON_API_URL', 'https://photon.komoot.io/api/'),
    ],

    'hostinger' => [
        'token' => env('HOSTINGER_API_TOKEN'),
        'virtual_machine_id' => env('HOSTINGER_VPS_ID'),
        'base_url' => env('HOSTINGER_API_URL', 'https://developers.hostinger.com/api'),
    ],

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

];
