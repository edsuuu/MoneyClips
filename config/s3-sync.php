<?php

declare(strict_types=1);

return [
    'source' => [
        'endpoint' => env('S3_SYNC_SOURCE_ENDPOINT', 'https://usc1.contabostorage.com'),
        'region' => env('S3_SYNC_SOURCE_REGION', 'usc1'),
        'access_key' => env('S3_SYNC_SOURCE_ACCESS_KEY'),
        'secret_key' => env('S3_SYNC_SOURCE_SECRET_KEY'),
        'bucket' => env('S3_SYNC_SOURCE_BUCKET', 'video'),
        'prefix' => env('S3_SYNC_SOURCE_PREFIX', 'shorts'),
    ],

    'destination' => [
        'endpoint' => env('S3_SYNC_DEST_ENDPOINT', env('MINIO_ENDPOINT', 'http://127.0.0.1:9000')),
        'region' => env('S3_SYNC_DEST_REGION', env('MINIO_REGION', 'us-east-1')),
        'access_key' => env('S3_SYNC_DEST_ACCESS_KEY', env('MINIO_KEY', 'minioadmin')),
        'secret_key' => env('S3_SYNC_DEST_SECRET_KEY', env('MINIO_SECRET', 'minioadmin')),
        'bucket' => env('S3_SYNC_DEST_BUCKET'),
    ],
];
