<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| YouTube Shorts (pipeline de auto-postagem)
|--------------------------------------------------------------------------
|
| Configuração do pipeline de Shorts (unificado do projeto auto-post):
| download de Shorts de canais via microserviço download-shorts (ver
| config/shorts-downloader.php) → sorteio e postagem automática no canal
| conectado (social_accounts, platform=youtube).
|
*/

return [

    // Disk de onde os vídeos do estoque são lidos para postagem.
    'disk' => env('YOUTUBE_SHORTS_DISK', 'minio'),

    /*
    |--------------------------------------------------------------------------
    | Postagem
    |--------------------------------------------------------------------------
    |
    | privacy_status: public | unlisted | private
    | category_id: 22 = People & Blogs (padrão seguro p/ a maioria dos canais)
    | posts_per_run: quantos jobs de postagem o comando youtube:dispatch-posts
    |   cria por execução (sobrescrevível por --count).
    | low_stock_threshold: quando a fração de Shorts ainda não postados cair
    |   até este limiar (0.20 = 20%), dispara um aviso no Discord.
    |
    */

    'posting' => [
        'privacy_status' => env('YOUTUBE_PRIVACY_STATUS', 'public'),
        'category_id' => env('YOUTUBE_CATEGORY_ID', '22'),
        'posts_per_run' => (int) env('YOUTUBE_POSTS_PER_RUN', 1),
        'low_stock_threshold' => (float) env('YOUTUBE_LOW_STOCK_THRESHOLD', 0.20),

        // Liga/desliga cada plataforma na auto-postagem (cron). Para pausar uma,
        // defina a env como false no .env e rode `php artisan config:clear`.
        'youtube_enabled' => (bool) env('AUTO_POST_YOUTUBE_ENABLED', true),
        'tiktok_enabled' => (bool) env('AUTO_POST_TIKTOK_ENABLED', true),
    ],

    // Webhook do Discord para notificar postagens, falhas e estoque baixo.
    // Vazio = notificações desativadas.
    'discord_webhook' => env('YOUTUBE_SHORTS_DISCORD_WEBHOOK', env('DISCORD_WEBHOOK_URL', '')),
];
