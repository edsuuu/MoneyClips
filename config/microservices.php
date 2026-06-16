<?php

declare(strict_types=1);

return [
    'download_youtube' => [
        'base_url' => env('DOWNLOAD_YOUTUBE_URL', 'http://127.0.0.1:8770'),
        'timeout' => (int) env('DOWNLOAD_YOUTUBE_TIMEOUT', 30),
    ],

    'tiktok_post' => [
        'base_url' => env('TIKTOK_POST_URL', 'http://127.0.0.1:8090'),
        'timeout' => (int) env('TIKTOK_POST_TIMEOUT', 30),
        'api_token' => env('TIKTOK_POST_API_TOKEN', ''),
        'callback_url' => env(
            'TIKTOK_POST_CALLBACK_URL',
            mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/tiktok-posts/callback',
        ),
    ],
];
