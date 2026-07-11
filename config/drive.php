<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Google Drive (pastas públicas)
    |--------------------------------------------------------------------------
    |
    | As pastas são compartilhadas como "qualquer pessoa com o link", então a
    | Drive API v3 aceita só uma API key (sem OAuth) para listar e baixar. Crie
    | a chave no Google Cloud Console (APIs & Services → Credentials) com a
    | Drive API habilitada e coloque em GOOGLE_DRIVE_API_KEY.
    |
    */
    'api_key' => env('GOOGLE_DRIVE_API_KEY', ''),

    'timeout' => (int) env('GOOGLE_DRIVE_TIMEOUT', 300),

    // Pausa entre downloads (ms) pra não disparar o bloqueio "tráfego incomum"
    // (403 Sorry) do Google em rajadas. E nº de tentativas por arquivo (o
    // download faz backoff exponencial: 5s, 15s, 45s...).
    'download_delay_ms' => (int) env('GOOGLE_DRIVE_DOWNLOAD_DELAY_MS', 800),
    'download_retries' => (int) env('GOOGLE_DRIVE_DOWNLOAD_RETRIES', 4),

    // Prefixo no bucket videos do MinIO (irmão de "shorts"): drive/<pasta>/<arquivo>.
    'path_prefix' => 'drive',

    /*
    | Lista de pastas percorridas pelo comando `drive:download-videos` quando
    | nenhuma URL é passada via --folder. Cada link é uma pasta pública.
    */
    'folders' => [
        'https://drive.google.com/drive/folders/1ntLulVhJ23SKi-8wdkGT0s8BCXVbzBbm',
        'https://drive.google.com/drive/folders/1fKIVdA_ugYH7Ywq6JwNvjiIKL_PCR1KD',
        'https://drive.google.com/drive/folders/1Xd5qmjLmNCoKjDS9h0ovoh9jgh4a5Myf',
        'https://drive.google.com/drive/folders/1H0J8ins7N70q1noJVi2u-WDSEoJDSMyk',
        'https://drive.google.com/drive/folders/177HAVKkppJNiXqgu70E_GcNldbLSlZze',
        'https://drive.google.com/drive/folders/1BhDAwEAUM8P-eEJW7qyJJn-KkMI0u9D2',
        'https://drive.google.com/drive/folders/1snmPKrownnl5kip99kzIwlh_vJK9HLYv',
        'https://drive.google.com/drive/folders/11l9Y9Kooj9nGlbvLKPtbod5ENUdRI0M9',
        'https://drive.google.com/drive/folders/1mx395vJDUs_RR86pSGqLuJZHryveBxAw',
        'https://drive.google.com/drive/folders/1UUosc6aha7Z-71zXeSEkH-BaEY6m_B-N',
        'https://drive.google.com/drive/folders/1Xf9TXq492nPda2Jwii024IJTU7_I6xvV',
    ],

];
