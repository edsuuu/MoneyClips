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
            'youtube' => env('GOOGLE_YOUTUBE_REDIRECT_URI', mb_rtrim((string) env('APP_URL'), '/').'/oauth/youtube/callback'),
        ],
        'youtube_scopes' => array_values(array_filter(array_map(
            'mb_trim',
            explode(',', (string) env(
                'GOOGLE_YOUTUBE_SCOPES',
                'https://www.googleapis.com/auth/youtube.upload,https://www.googleapis.com/auth/youtube.readonly',
            )),
        ))),
    ],

    'download_youtube' => [
        'base_url' => env('DOWNLOAD_YOUTUBE_URL', 'http://127.0.0.1:8770'),
        'timeout' => (int) env('DOWNLOAD_YOUTUBE_TIMEOUT', 30),
        'webhook_url' => env(
            'DOWNLOAD_YOUTUBE_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhook/download-youtube',
        ),
        'video_webhook_url' => env(
            'DOWNLOAD_YOUTUBE_VIDEO_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhook/download-video',
        ),
    ],

    'tiktok_post' => [
        'base_url' => env('TIKTOK_POST_URL', 'http://127.0.0.1:8090'),
        // Só cobre o envio do binário + 202 {job_id} — a publicação roda em
        // background no uploader e o desfecho volta pela webhook_url.
        'timeout' => (int) env('TIKTOK_POST_TIMEOUT', 120),
        'api_token' => env('TIKTOK_POST_API_TOKEN', ''),
        'webhook_url' => env(
            'TIKTOK_POST_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhook/tiktok-posts',
        ),
        // Conta TikTok ativa (handle público sem @). Usada em mensagens do
        // Discord pra rotular o destino. A autenticação real vive em cookies.
        'account_name' => env('TIKTOK_ACCOUNT_NAME', ''),
    ],

    'hls' => [
        'base_url' => env('HLS_URL', 'http://127.0.0.1:8790'),
        'timeout' => (int) env('HLS_TIMEOUT', 60),
        'api_token' => env('HLS_API_TOKEN', ''),
        'webhook_url' => env(
            'HLS_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhook/hls',
        ),
        // `accel` delega o streaming dos segmentos ao nginx (X-Accel-Redirect);
        // `stream` devolve os bytes pelo PHP, para `artisan serve`, que não
        // entende o header.
        'delivery' => env('HLS_DELIVERY', 'accel'),
    ],

    // Corte de trechos (endpoint /cut do mesmo serviço :8790 — base_url e token
    // vêm de `services.hls`; aqui só o retorno do webhook).
    'cut' => [
        'webhook_url' => env(
            'CUT_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhook/cut',
        ),
    ],

    // Render do corte editado (endpoint /reframe do mesmo serviço :8790).
    'video_cut_edit' => [
        'webhook_url' => env(
            'VIDEO_CUT_EDIT_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhook/video-cut-edit',
        ),
    ],

    'observability' => [
        // Token compartilhado dos endpoints /api/observability/* (header
        // X-Observability-Token). Precisa bater com o OBSERVABILITY_TOKEN
        // configurado em cada microserviço.
        'token' => env('OBSERVABILITY_TOKEN', ''),
    ],

    // Transcrição (endpoint /transcriptions do serviço media, :8770) — o Laravel
    // fala direto com ele: manda o wav, recebe 202 e o desfecho chega por webhook.
    // Timeout curto porque a chamada só espera o 202 (a transcrição roda
    // assíncrona no serviço).
    'transcribe' => [
        'base_url' => env('TRANSCRIBE_URL', 'http://127.0.0.1:8770'),
        'timeout' => (int) env('TRANSCRIBE_TIMEOUT', 60),
        'webhook_url' => env(
            'TRANSCRIBE_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhook/transcribe',
        ),
    ],

    'youtube_shorts' => [
        'posting' => [
            'privacy_status' => 'public',
            'category_id' => '22',
            'posts_per_run' => 1,
            'low_stock_threshold' => 0.20,
            'youtube_enabled' => filter_var(env('YOUTUBE_POSTING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'tiktok_enabled' => filter_var(env('TIKTOK_POSTING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],
        'discord_webhook' => env('DISCORD_WEBHOOK_URL', ''),
    ],

];
