<?php

declare(strict_types=1);

return [
    /*
    | URL base do microserviço tiktok-uploader (Node + Playwright). Ele baixa
    | o vídeo do storage, publica no TikTok via navegador e grava o ciclo de
    | vida do post direto na tabela tiktok_posts DESTE banco.
    */
    'base_url' => env('TIKTOK_UPLOADER_URL', 'http://127.0.0.1:8780'),

    /*
    | Timeout (segundos) das chamadas HTTP síncronas ao microserviço. As
    | chamadas só enfileiram (a publicação é assíncrona), então pode ser curto.
    */
    'timeout' => (int) env('TIKTOK_UPLOADER_TIMEOUT', 30),
];
