<?php

declare(strict_types=1);

namespace App\Services\S3Sync;

use Aws\Command;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

/**
 * Faz o stream do objeto de origem direto pro destino via MultipartUploader.
 * Não materializa o arquivo em disco — usa o body do GetObject como source
 * e o uploader cuida do chunking.
 */
final readonly class ObjectCopier
{
    public function __construct(
        private S3Client $source,
        private S3Client $destination,
    ) {}

    public function copy(string $key, string $sourceBucket, string $destinationBucket, int $partSizeBytes): void
    {
        $get = $this->source->getObject([
            'Bucket' => $sourceBucket,
            'Key' => $key,
        ]);

        $rawContentType = $get['ContentType'] ?? null;
        $contentType = is_string($rawContentType) ? $rawContentType : null;

        $uploader = new MultipartUploader($this->destination, $get['Body'], [
            'bucket' => $destinationBucket,
            'key' => $key,
            'part_size' => $partSizeBytes,
            'before_initiate' => function (Command $command) use ($contentType): void {
                if ($contentType !== null) {
                    $command['ContentType'] = $contentType;
                }
            },
        ]);

        $uploader->upload(); // @phpstan-ignore method.internalClass
    }
}
