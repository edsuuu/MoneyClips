<?php

declare(strict_types=1);

namespace App\Services\VideoProcessor;

use App\Models\File;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class Uploader
{
    public function upload(Video $video, string $localPath, string $type): File
    {
        $log = Log::channel('daily');
        $ctx = ['video_id' => $video->id, 'type' => $type, 'local_path' => $localPath];

        if (! file_exists($localPath) || filesize($localPath) === 0) {
            $log->error('[Uploader] Arquivo local não encontrado ou vazio.', $ctx);
            throw new RuntimeException("Arquivo local não encontrado ou vazio: {$localPath}");
        }

        $disk = config('filesystems.default');
        $extension = pathinfo($localPath, PATHINFO_EXTENSION);
        $remotePath = "videos/{$video->uuid}/{$type}.{$extension}";
        $mimeType = $this->mimeType($extension);
        $sizeBytes = (int) filesize($localPath);
        $checksum = hash_file('sha256', $localPath);

        $log->info('[Uploader] Iniciando upload.', $ctx + [
            'disk'        => $disk,
            'remote_path' => $remotePath,
            'size_bytes'  => $sizeBytes,
            'mime_type'   => $mimeType,
        ]);

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            $log->error('[Uploader] Não foi possível abrir o arquivo.', $ctx);
            throw new RuntimeException("Não foi possível abrir o arquivo: {$localPath}");
        }

        try {
            Storage::put($remotePath, $stream, [
                'ContentType' => $mimeType,
            ]);
        } finally {
            fclose($stream);
        }

        $log->info('[Uploader] Upload concluído.', $ctx + ['remote_path' => $remotePath]);

        return $video->files()->create([
            'type'            => $type,
            'disk'            => $disk,
            'bucket'          => config('filesystems.disks.'.$disk.'.bucket'),
            'path'            => $remotePath,
            'mime_type'       => $mimeType,
            'extension'       => $extension,
            'size_bytes'      => $sizeBytes,
            'checksum_sha256' => $checksum,
        ]);
    }

    private function mimeType(string $extension): string
    {
        return match (mb_strtolower($extension)) {
            'mp4'  => 'video/mp4',
            'mkv'  => 'video/x-matroska',
            'webm' => 'video/webm',
            'mov'  => 'video/quicktime',
            'm4a'  => 'audio/mp4',
            'mp3'  => 'audio/mpeg',
            'opus' => 'audio/opus',
            default => 'application/octet-stream',
        };
    }
}
