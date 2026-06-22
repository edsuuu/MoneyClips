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
    | Credenciais das redes sociais
    |--------------------------------------------------------------------------
    | Google cobre o YouTube; Facebook cobre Instagram + Facebook (Meta).
    | Os tokens das contas conectadas vivem em social_accounts.
    */
    'google' => [
        'client_id' => env('GOOGLE_AUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_AUTH_CLIENT_SECRET'),
        'redirects' => [
            'auth' => env('GOOGLE_AUTH_REDIRECT_URI', mb_rtrim((string) env('APP_URL'), '/').'/oauth2/google/callback'),
            'youtube' => mb_rtrim((string) env('APP_URL'), '/').'/oauth/youtube/callback',
        ],
    ],

    'download_youtube' => [
        'base_url' => env('DOWNLOAD_YOUTUBE_URL', 'http://127.0.0.1:8770'),
        'timeout' => (int) env('DOWNLOAD_YOUTUBE_TIMEOUT', 30),
        'webhook_url' => env(
            'DOWNLOAD_YOUTUBE_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/download-youtube/webhook',
        ),
    ],

    'tiktok_post' => [
        'base_url' => env('TIKTOK_POST_URL', 'http://127.0.0.1:8090'),
        'timeout' => (int) env('TIKTOK_POST_TIMEOUT', 30),
        'api_token' => env('TIKTOK_POST_API_TOKEN', ''),
        // Conta TikTok ativa (handle público sem @). Usada em mensagens do
        // Discord pra rotular o destino. A autenticação real vive em cookies.
        'account_name' => env('TIKTOK_ACCOUNT_NAME', ''),
        'callback_url' => env(
            'TIKTOK_POST_CALLBACK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/tiktok-posts/callback',
        ),
        // Token compartilhado entre a extensão Chrome (tiktok-cookie-bridge)
        // e o endpoint /api/tiktok/cookies/ingest. Vazio = endpoint desligado.
        'bridge_token' => env('TIKTOK_BRIDGE_TOKEN', ''),
    ],

    'youtube_shorts' => [
        'posting' => [
            'privacy_status' => 'public',
            'category_id' => '22',
            'posts_per_run' => 1,
            'low_stock_threshold' => 0.20,
            'youtube_enabled' => true,
            'tiktok_enabled' => true,
        ],
        'discord_webhook' => env('DISCORD_WEBHOOK_URL', ''),
    ],

    's3_sync' => [
        'source' => [
            'endpoint' => env('S3_SYNC_SOURCE_ENDPOINT', 'https://usc1.contabostorage.com'),
            'region' => env('S3_SYNC_SOURCE_REGION', 'usc1'),
            'access_key' => env('S3_SYNC_SOURCE_ACCESS_KEY'),
            'secret_key' => env('S3_SYNC_SOURCE_SECRET_KEY'),
            'bucket' => env('S3_SYNC_SOURCE_BUCKET', 'video'),
            'prefix' => env('S3_SYNC_SOURCE_PREFIX', 'shorts'),
        ],
    ],

];
