<?php

declare(strict_types=1);

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

    /*
    |--------------------------------------------------------------------------
    | OAuth das redes sociais (Socialite)
    |--------------------------------------------------------------------------
    | Google cobre o YouTube; Facebook cobre Instagram + Facebook (Meta).
    | Crie os apps nos consoles de cada plataforma e registre as redirect URIs
    | abaixo. Os tokens das contas conectadas vivem em social_accounts.
    */
    'google' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect' => env('YOUTUBE_REDIRECT_URI', mb_rtrim((string) env('APP_URL'), '/').'/oauth/youtube/callback'),
    ],

    'facebook' => [
        'client_id' => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET'),
        'redirect' => env('META_REDIRECT_URI', mb_rtrim((string) env('APP_URL'), '/').'/oauth/facebook/callback'),
    ],

    'tiktok' => [
        'client_id' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI', mb_rtrim((string) env('APP_URL'), '/').'/oauth/tiktok/callback'),
    ],

];
