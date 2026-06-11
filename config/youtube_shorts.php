<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| YouTube Shorts (funcionalidade isolada)
|--------------------------------------------------------------------------
|
| Configuração exclusiva da funcionalidade de download de Shorts de canais
| do YouTube. Mantida em arquivo próprio para não tocar em nada existente.
|
*/

return [

    // Binário do yt-dlp (ajuste se não estiver no PATH).
    'yt_dlp_bin' => env('YT_DLP_BIN', 'yt-dlp'),

    // Disk do MinIO onde os vídeos baixados são salvos.
    'disk' => env('YOUTUBE_SHORTS_DISK', 'minio'),

    // Pasta dentro do bucket.
    'path_prefix' => 'shorts',

    // Webhook do Discord para notificar postagens realizadas.
    'discord_webhook' => env(
        'YOUTUBE_SHORTS_DISCORD_WEBHOOK',
        'REDACTED_DISCORD_WEBHOOK',
    ),

    // Horários permitidos para postagem (HH:00).
    'allowed_hours' => [0, 3, 6, 9, 11, 14, 17, 20, 23],

    // Máximo de posts por dia (dos horários disponíveis, usa apenas estes).
    'max_per_day' => 5,
];
