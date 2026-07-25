<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Services\Upload\Data\MultipartSessionData;
use App\Services\Upload\Data\UploadPartData;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class MultipartUploadService implements MultipartUploadInterface
{
    public const int PART_SIZE = 32 * 1024 * 1024;

    public const int MAX_PARTS = 10000;

    private const string PART_URL_TTL = '+2 hours';

    /**
     * @throws Throwable
     */
    public function create(string $key, int $fileSize, string $mimeType): MultipartSessionData
    {
        $result = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ContentType' => $mimeType,
        ]);

        return new MultipartSessionData(
            uploadId: (string) $result['UploadId'],
            partSize: self::PART_SIZE,
            partCount: self::partCountFor($fileSize),
        );
    }

    /**
     * @param  list<int>  $partNumbers
     * @return array<int, string>
     *
     * @throws Throwable
     */
    public function signParts(string $key, string $uploadId, array $partNumbers): array
    {
        $client = $this->client();
        $urls = [];

        foreach ($partNumbers as $partNumber) {
            $command = $client->getCommand('UploadPart', [
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
            ]);

            $urls[$partNumber] = (string) $client->createPresignedRequest($command, self::PART_URL_TTL)->getUri();
        }

        return $urls;
    }

    /**
     * @param  list<UploadPartData>  $parts
     *
     * @throws Throwable
     */
    public function complete(string $key, string $uploadId, array $parts): void
    {
        $ordered = $parts;
        usort($ordered, fn (UploadPartData $a, UploadPartData $b): int => $a->partNumber <=> $b->partNumber);

        $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(
                fn (UploadPartData $part): array => $part->toArray(),
                $ordered,
            )],
        ]);
    }

    /**
     * @throws Throwable
     */
    public function abort(string $key, string $uploadId): void
    {
        $this->client()->abortMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function size(string $key): int
    {
        $result = $this->client()->headObject([
            'Bucket' => $this->bucket(),
            'Key' => $key,
        ]);

        return (int) $result['ContentLength'];
    }

    /**
     * Range GET: o objeto pode ter gigabytes e só o cabeçalho interessa.
     *
     * @throws Throwable
     */
    public function firstBytes(string $key, int $length): string
    {
        $result = $this->client()->getObject([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'Range' => sprintf('bytes=0-%d', $length - 1),
        ]);

        return (string) $result['Body'];
    }

    /**
     * Partes já gravadas — permite retomar um upload interrompido sem reenviar
     * o que já subiu.
     *
     * @return list<UploadPartData>
     *
     * @throws Throwable
     */
    public function listParts(string $key, string $uploadId): array
    {
        $parts = [];
        $marker = 0;

        do {
            $result = $this->client()->listParts([
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumberMarker' => $marker,
            ]);

            /** @var list<array{PartNumber: int, ETag: string}> $chunk */
            $chunk = is_array($result['Parts'] ?? null) ? $result['Parts'] : [];

            foreach ($chunk as $part) {
                $parts[] = new UploadPartData((int) $part['PartNumber'], (string) $part['ETag']);
            }

            $marker = (int) ($result['NextPartNumberMarker'] ?? 0);
        } while ($result['IsTruncated'] ?? false);

        return $parts;
    }

    public static function partCountFor(int $fileSize): int
    {
        return max(1, (int) ceil($fileSize / self::PART_SIZE));
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.s3.bucket');
    }

    /**
     * @throws Throwable
     */
    private function client(): S3Client
    {
        $disk = Storage::disk('s3');

        throw_unless($disk instanceof AwsS3V3Adapter, RuntimeException::class, 'O disco s3 precisa do driver AWS para assinar uploads multipart.');

        return $disk->getClient();
    }
}
