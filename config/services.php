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

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    /*
    |--------------------------------------------------------------------------
    | Office API Configuration
    |--------------------------------------------------------------------------
    |
    | API token for external services to access office data endpoints.
    | Used by the online ordering service (mossorders) to sync product data.
    |
    */

    'office' => [
        'api_token' => env('OFFICE_API_TOKEN'),
        // Previous token, still accepted during a rotation window.
        // Set to the OLD value when you rotate OFFICE_API_TOKEN, then
        // clear it once all clients have switched.
        'api_token_previous' => env('OFFICE_API_TOKEN_PREVIOUS'),
        // Comma-separated list of allowed caller IPs/CIDRs. Leave blank to
        // skip the check. Example: "203.0.113.4,198.51.100.0/24".
        'allowed_ips' => env('OFFICE_API_ALLOWED_IPS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mossorders Online Portal API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to the Mossorders online ordering portal.
    | Used by the office system to fetch orders from the online portal.
    |
    */

    'mossorders' => [
        'base_url' => env('MOSSORDERS_BASE_URL', 'https://mossorders.example.com'),
        'api_token' => env('MOSSORDERS_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Scheduler
    |--------------------------------------------------------------------------
    |
    | Toggle for the scheduled sync commands. Leave false until logs have
    | been verified in staging; flip to true in production to start the
    | hourly import.
    |
    */

    'sync' => [
        'enabled' => filter_var(env('SYNC_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
