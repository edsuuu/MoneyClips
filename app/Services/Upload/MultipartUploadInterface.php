<?php

declare(strict_types=1);

namespace App\Services\Upload;

/**
 * Fronteira do upload multipart. Existe para manter o service `final` (regra da
 * casa) e ainda assim permitir substituí-lo nos testes, que não têm MinIO.
 */
interface MultipartUploadInterface
{
    public function create(string $key, int $fileSize, string $mimeType): MultipartSessionData;

    /**
     * @param  list<int>  $partNumbers
     * @return array<int, string>
     */
    public function signParts(string $key, string $uploadId, array $partNumbers): array;

    /**
     * @param  list<UploadPartData>  $parts
     */
    public function complete(string $key, string $uploadId, array $parts): void;

    public function abort(string $key, string $uploadId): void;

    public function size(string $key): int;

    /**
     * @return list<UploadPartData>
     */
    public function listParts(string $key, string $uploadId): array;
}
