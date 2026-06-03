<?php

declare(strict_types=1);

namespace App\Services\VideoProcessor;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

final readonly class Downloader
{
    public const string FORMAT_VIDEO = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best[ext=mp4]/best';

    public const string FORMAT_AUDIO = 'bestaudio[ext=m4a]/bestaudio';

    private string $outputDir;

    public function __construct(
        private string $ytDlpBin = 'yt-dlp',
    ) {
        $this->outputDir = storage_path('app/private/videos');

        if (! mkdir($concurrentDirectory = $this->outputDir, 0755, true) && ! is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
    }

    /** @return array{video_id: string, title: string, duration: float, file_path: string}
     * @throws JsonException
     */
    public function downloadVideo(string $url, ?callable $onProgress = null): array
    {
        $log = Log::channel('daily');

        $log->info('[Downloader] Buscando metadados do vídeo.', ['url' => $url]);

        $meta = $this->fetchMetadata($url);
        $log->info('[Downloader] Metadados obtidos.', ['video_id' => $meta['id'], 'title' => $meta['title'], 'duration' => $meta['duration']]);

        $videoDir = $this->videoDir($meta['id']);

        $log->info('[Downloader] Iniciando download do vídeo.', ['video_id' => $meta['id']]);
        $this->runDownload($url, $videoDir, 'source', self::FORMAT_VIDEO, $onProgress);

        $filePath = $this->findFile($videoDir, 'source', ['mp4', 'mkv', 'webm', 'mov']);
        if (! $filePath) {
            $log->error('[Downloader] Arquivo de vídeo não encontrado após download.', ['dir' => $videoDir]);
            throw new RuntimeException('Arquivo de vídeo não encontrado em ' . $videoDir);
        }

        $log->info('[Downloader] Vídeo baixado com sucesso.', ['file_path' => $filePath]);

        return [
            'video_id' => $meta['id'],
            'title' => $meta['title'],
            'duration' => $meta['duration'],
            'file_path' => $filePath,
        ];
    }

    /** @return array{video_id: string, title: string, duration: float, file_path: string}
     * @throws JsonException
     */
    public function downloadAudio(string $url, ?callable $onProgress = null): array
    {
        $log = Log::channel('daily');

        $log->info('[Downloader] Buscando metadados do áudio.', ['url' => $url]);

        $meta = $this->fetchMetadata($url);

        $videoDir = $this->videoDir($meta['id']);

        $log->info('[Downloader] Iniciando download do áudio.', ['video_id' => $meta['id']]);
        $this->runDownload($url, $videoDir, 'audio', self::FORMAT_AUDIO, $onProgress);

        $filePath = $this->findFile($videoDir, 'audio', ['m4a', 'mp3', 'opus', 'webm']);
        if (! $filePath) {
            $log->error('[Downloader] Arquivo de áudio não encontrado após download.', ['dir' => $videoDir]);
            throw new RuntimeException('Arquivo de áudio não encontrado em ' . $videoDir);
        }

        $log->info('[Downloader] Áudio baixado com sucesso.', ['file_path' => $filePath]);

        return [
            'video_id' => $meta['id'],
            'title' => $meta['title'],
            'duration' => $meta['duration'],
            'file_path' => $filePath,
        ];
    }

    // ── privados ──────────────────────────────────────────────────────────────

    /** @return array{id: string, title: string, duration: float}
     * @throws JsonException
     */
    private function fetchMetadata(string $url): array
    {
        $result = Process::run([
            $this->ytDlpBin,
            '-J',
            '--no-playlist',
            '--quiet',
            '--no-warnings',
            $url,
        ]);

        if (! $result->successful()) {
            Log::channel('daily')->error('[Downloader] yt-dlp metadata falhou.', [
                'url' => $url,
                'stderr' => $result->errorOutput(),
            ]);
            throw new RuntimeException('yt-dlp metadata falhou: '.$result->errorOutput());
        }

        $meta = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => $meta['id'],
            'title' => $meta['title'] ?? $meta['id'],
            'duration' => (float) ($meta['duration'] ?? 0),
        ];
    }

    private function runDownload(string $url, string $videoDir, string $filename, string $format, ?callable $onProgress): void
    {
        $process = Process::timeout(3600)->start([
            $this->ytDlpBin,
            '-f', $format,
            '--merge-output-format', 'mp4',
            '--remux-video', 'mp4',
            '-o', sprintf('%s/%s.%%(ext)s', $videoDir, $filename),
            '--no-playlist',
            '--no-warnings',
            '--newline',
            '--progress-template', 'download:[%(progress.downloaded_bytes)s/%(progress.total_bytes_estimate)s]',
            $url,
        ]);

        foreach ($process as $output) {
            if ($onProgress && preg_match('/\[(\d+)\/(\d+)]/', (string) $output, $m)) {
                $done = (int) $m[1];
                $total = (int) $m[2];
                if ($total > 0) {
                    $onProgress(min(99.0, $done / $total * 100));
                }
            }
        }

        $result = $process->wait();

        if (! $result->successful()) {
            Log::channel('daily')->error('[Downloader] yt-dlp download falhou.', [
                'url' => $url,
                'stderr' => $result->errorOutput(),
            ]);
            throw new RuntimeException('yt-dlp download falhou: '.$result->errorOutput());
        }
    }

    private function findFile(string $videoDir, string $basename, array $extensions): ?string
    {
        foreach ($extensions as $ext) {
            $path = sprintf('%s/%s.%s', $videoDir, $basename, $ext);
            if (file_exists($path) && filesize($path) > 0) {
                return $path;
            }
        }

        return null;
    }

    private function videoDir(string $videoId): string
    {
        $dir = sprintf('%s/%s', $this->outputDir, $videoId);

        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        return $dir;
    }
}
