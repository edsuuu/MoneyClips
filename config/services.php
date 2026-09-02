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
            mb_trim(...),
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

    // Face tracking (endpoint /face-tracking do serviço media, :8770). Mesmo
    // desenho da transcrição: manda o clip por multipart, recebe 202 e os
    // keyframes + timeline de locutor chegam por webhook. O teto de keyframes
    // é o que mantém o filtergraph do /reframe editável e barato — o serviço
    // simplifica a curva até caber nele.
    'face_tracking' => [
        'base_url' => env('FACE_TRACKING_URL', 'http://127.0.0.1:8770'),
        'timeout' => (int) env('FACE_TRACKING_TIMEOUT', 120),
        'max_keyframes' => (int) env('FACE_TRACKING_MAX_KEYFRAMES', 40),
        'webhook_url' => env(
            'FACE_TRACKING_WEBHOOK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhook/face-tracking',
        ),
    ],

    // Sugestão de cortes por LLM. As travas abaixo não confiam na resposta do
    // modelo: duração, gap e ordem são reimpostos no Laravel depois.
    'cut_suggestion' => [
        'min_duration' => (int) env('CUT_SUGGESTION_MIN_DURATION', 60),
        'max_duration' => (int) env('CUT_SUGGESTION_MAX_DURATION', 80),
        'min_gap' => (float) env('CUT_SUGGESTION_MIN_GAP', 1.0),
        'max_cuts' => (int) env('CUT_SUGGESTION_MAX_CUTS', 20),
    ],

    'discord' => [
        'webhook' => env('DISCORD_WEBHOOK_URL', ''),
    ],

];
