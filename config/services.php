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

    'qz' => [
        'certificate_path' => env('QZ_CERT_PATH', env('QZ_CERTIFICATE_PATH', 'storage/app/qz/digital-certificate.txt')),
        'certificate_base64' => env('QZ_CERTIFICATE_BASE64'),
        'private_key_path' => env('QZ_PRIVATE_KEY_PATH', 'storage/app/qz/private-key.pem'),
        'private_key_base64' => env('QZ_PRIVATE_KEY_BASE64'),
        'printer_name' => env('QZ_PRINTER_NAME', 'Generic / Text Only'),
    ],

];
