<?php

declare(strict_types=1);

return [
    /*
    | URL base da API de processamento (FastAPI). O Laravel orquestra; o Python processa.
    */
    'base_url' => env('PYTHON_API_BASE_URL', 'http://127.0.0.1:8765'),

    /*
    | URL base do WebSocket da API de processamento (consumido pelo browser para
    | mostrar progresso em tempo real). Ex: ws://127.0.0.1:8765
    */
    'ws_url' => env('PYTHON_API_WS_URL', 'ws://127.0.0.1:8765'),

    /*
    | Token enviado no header Authorization das chamadas Laravel -> Python.
    | Deve casar com PYTHON_API_TOKEN no .env do projeto Python.
    | O Laravel orquestra; o Python processa.
    */
    'token' => env('PYTHON_API_TOKEN', ''),

    /*
    | Timeout (segundos) das chamadas HTTP síncronas.
    */
    'timeout' => (int) env('PYTHON_API_TIMEOUT', 120),

    /*
    | Token que o Python deve enviar de volta no webhook de callback.
    | Validado em POST /api/video-processor/callbacks.
    */
    'callback_token' => env('PYTHON_CALLBACK_TOKEN', ''),

    /*
    | URL pública do callback que o Laravel envia ao processador.
    */
    'callback_url' => env('APP_URL', 'http://localhost:8000').'/api/video-processor/callbacks',

    /*
    | Disco (filesystem) usado para o MinIO, compartilhado com o Python.
    */
    'storage_disk' => 'minio',
    'storage_bucket' => env('MINIO_BUCKET', 'auto-post'),

    /*
    | Configuração do "piloto automático" (is_auto). Decide a estratégia de cortes:
    | - vídeos com duração <= auto.full_coverage_max_seconds são fatiados em clipes
    |   sequenciais de auto.clip_seconds cobrindo o vídeo inteiro.
    | - vídeos mais longos usam a IA para escolher apenas os melhores momentos.
    */
    'auto' => [
        // Limite (segundos) para cobrir o vídeo inteiro em clipes sequenciais. Default 15min.
        'full_coverage_max_seconds' => (int) env('AUTO_FULL_COVERAGE_MAX_SECONDS', 900),
        // Duração-alvo de cada clipe sequencial (segundos).
        'clip_seconds' => (int) env('AUTO_CLIP_SECONDS', 60),
        // Sobra final menor que isso é absorvida pelo último clipe em vez de virar um clipe curto.
        'min_tail_seconds' => (int) env('AUTO_MIN_TAIL_SECONDS', 20),
        // Faixa de quantidade de cortes pedida à IA para vídeos longos.
        'ai_min_cuts' => (int) env('AUTO_AI_MIN_CUTS', 8),
        'ai_max_cuts' => (int) env('AUTO_AI_MAX_CUTS', 20),
    ],
];
