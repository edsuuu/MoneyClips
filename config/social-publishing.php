<?php

declare(strict_types=1);

return [
    /*
    | Versão da Graph API usada pelos publishers de Instagram e Facebook.
    */
    'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),

    /*
    | Tentativas de publicação antes de marcar o post como failed.
    */
    'max_attempts' => (int) env('SOCIAL_PUBLISH_MAX_ATTEMPTS', 3),

    /*
    | Usuário dono das contas sociais conectadas (OAuth e cadastro manual).
    | Default: 1 (admin). As contas são compartilhadas/administradas por ele,
    | já que a publicação roda em background (sem usuário logado).
    */
    'account_owner_id' => (int) env('SOCIAL_ACCOUNT_OWNER_ID', 1),

    /*
    | Credenciais de app (client id/secret) por plataforma — usadas para refresh
    | de token e, futuramente, para o fluxo OAuth. Os tokens das contas ficam em
    | social_accounts (criptografados).
    */
    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
    ],
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
    ],
];
