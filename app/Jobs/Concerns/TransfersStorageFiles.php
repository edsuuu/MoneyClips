<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transferência MinIO ⇄ arquivo temporário local (streaming), usada pelos
 * jobs do pipeline de processamento — a regra da casa é o Laravel ser o
 * único a tocar o S3.
 */
trait TransfersStorageFiles
{
    private function pullToTemp(string $key, string $prefix): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), $prefix);
        $read = Storage::disk('s3')->readStream($key);
        throw_unless(is_resource($read), RuntimeException::class, sprintf('Falha ao ler "%s" do MinIO.', $key));

        $write = fopen($tmp, 'wb');
        throw_unless(is_resource($write), RuntimeException::class, 'Falha ao criar arquivo temporário.');

        stream_copy_to_stream($read, $write);
        fclose($read);
        fclose($write);

        return $tmp;
    }

    private function pushToStorage(string $tmpPath, string $key): void
    {
        $stream = fopen($tmpPath, 'rb');
        throw_unless(is_resource($stream), RuntimeException::class, sprintf('Falha ao abrir "%s".', $tmpPath));

        try {
            Storage::disk('s3')->put($key, $stream);
        } finally {
            fclose($stream);
        }
    }
}
