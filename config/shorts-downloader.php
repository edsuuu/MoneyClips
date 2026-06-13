<?php

declare(strict_types=1);

return [
    /*
    | URL base do microserviço Python download-shorts (FastAPI). Ele lista os
    | Shorts do canal, baixa, sobe para o storage S3-compatible (Contabo) e
    | guarda tudo no banco DELE — o Laravel só cria o job e consulta o status.
    */
    'base_url' => env('SHORTS_DOWNLOADER_URL', 'http://127.0.0.1:8770'),

    /*
    | Timeout (segundos) das chamadas HTTP síncronas ao microserviço.
    */
    'timeout' => (int) env('SHORTS_DOWNLOADER_TIMEOUT', 30),
];
