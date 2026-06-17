<?php

declare(strict_types=1);

return [
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
    | Credenciais de app do YouTube — usadas para refresh de token e fluxo OAuth.
    | Os tokens das contas ficam em social_accounts (criptografados).
    */
    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY'),
        'client_id' => env('YOUTUBE_CLIENT_ID', env('GOOGLE_AUTH_CLIENT_ID')),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET', env('GOOGLE_AUTH_CLIENT_SECRET')),
    ],
];
