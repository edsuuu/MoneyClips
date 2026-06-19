<?php

declare(strict_types=1);

namespace App\Services\S3Sync;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

/**
 * Decide o que precisa ser copiado comparando tamanho/ETag entre origem e
 * destino. Quando o objeto não existe no destino, copia. Quando existe, copia
 * só se o tamanho diverge — ou, no caso de objeto simples (ETag sem traço),
 * se o ETag diverge.
 */
final readonly class ObjectPlanner
{
    public function __construct(private S3Client $destination) {}

    /**
     * @param  array<string, mixed>  $sourceObject  saída do ListObjectsV2 (Key/Size/ETag)
     */
    public function needsCopy(array $sourceObject, string $destinationBucket, bool $force): bool
    {
        if ($force) {
            return true;
        }

        $key = (string) ($sourceObject['Key'] ?? '');
        if ($key === '') {
            return false;
        }

        try {
            $head = $this->destination->headObject([
                'Bucket' => $destinationBucket,
                'Key' => $key,
            ]);
        } catch (AwsException) {
            return true;
        }

        $sourceSize = (int) ($sourceObject['Size'] ?? 0);
        $destinationSize = (int) ($head['ContentLength'] ?? 0);

        if ($sourceSize !== $destinationSize) {
            return true;
        }

        $sourceTag = mb_trim((string) ($sourceObject['ETag'] ?? ''), '"');
        $destinationTag = mb_trim((string) ($head['ETag'] ?? ''), '"');

        if ($sourceTag === '' || str_contains($sourceTag, '-')) {
            return false;
        }

        return $sourceTag !== $destinationTag;
    }
}
