<?php

declare(strict_types=1);

namespace App\Services\Youtube;

use App\Jobs\YoutubePostJob;
use App\Models\YoutubeShort;
use App\Models\YoutubeShortJob;
use App\Support\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Service isolado para listar e baixar Shorts de um canal do YouTube.
 *
 * Não depende nem altera nenhuma lógica existente do projeto. Usa o yt-dlp
 * via Process::run() e salva os vídeos no MinIO.
 */
final class YoutubeChannelService
{
    /**
     * Lista todos os Shorts de um canal.
     *
     * @return array<int, array{id: string, title: string, description: string, hashtags: array<int, string>, url: string}>
     */
    public function listShorts(string $channelUrl): array
    {
        $shortsUrl = $this->normalizeShortsUrl($channelUrl);

        $result = Process::timeout(600)->run([
            $this->bin(),
            '--flat-playlist',
            '--dump-json',
            '--sleep-requests', '2',
            '--min-sleep-interval', '1',
            $shortsUrl,
        ]);

        if (! $result->successful()) {
            Log::warning('[YoutubeChannelService] Falha ao listar Shorts.', [
                'url' => $shortsUrl,
                'error' => $result->errorOutput(),
            ]);

            return [];
        }

        $videos = [];

        foreach (preg_split('/\R/', mb_trim($result->output())) ?: [] as $line) {
            $line = mb_trim($line);
            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($line, true);
            if (! is_array($data) || empty($data['id'])) {
                continue;
            }

            $id = Cast::str($data['id']);
            $title = Cast::str($data['title'] ?? '');
            $description = Cast::str($data['description'] ?? '');

            $videos[] = [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'hashtags' => $this->parseHashtags($title.' '.$description),
                'url' => Cast::str($data['url'] ?? "https://www.youtube.com/shorts/{$id}"),
            ];
        }

        return $videos;
    }

    /**
     * Baixa um Short, salva no MinIO e persiste no banco.
     *
     * Retorna os dados do vídeo, ou null caso já exista ou falhe.
     *
     * @return array{youtube_id: string, title: string, description: string, hashtags: array<int, string>, minio_path: string}|null
     */
    public function downloadShort(string $youtubeId, ?string $url = null): ?array
    {
        // 1. Já baixado? Pula.
        if (YoutubeShort::query()->where('youtube_id', $youtubeId)->exists()) {
            return null;
        }

        $url ??= "https://www.youtube.com/shorts/{$youtubeId}";
        $tmpDir = mb_rtrim(sys_get_temp_dir(), '/').'/yt-short-'.Str::random(8);

        try {
            @mkdir($tmpDir, 0775, true);

            $template = $tmpDir.'/'.$youtubeId.'.%(ext)s';

            // 2. Baixa o vídeo (mp4) + metadados em JSON.
            $result = Process::timeout(600)->run([
                $this->bin(),
                '-f', 'mp4/bestvideo+bestaudio/best',
                '--merge-output-format', 'mp4',
                '--write-info-json',
                '--no-playlist',
                '--sleep-requests', '2',
                '--min-sleep-interval', '1',
                '-o', $template,
                $url,
            ]);

            if (! $result->successful()) {
                Log::warning('[YoutubeChannelService] Falha ao baixar Short.', [
                    'youtube_id' => $youtubeId,
                    'error' => $result->errorOutput(),
                ]);

                return null;
            }

            $videoFile = $tmpDir.'/'.$youtubeId.'.mp4';
            if (! is_file($videoFile)) {
                Log::warning('[YoutubeChannelService] Arquivo mp4 não encontrado após download.', [
                    'youtube_id' => $youtubeId,
                ]);

                return null;
            }

            // Metadados.
            $title = $youtubeId;
            $description = '';
            $infoFile = $tmpDir.'/'.$youtubeId.'.info.json';
            if (is_file($infoFile)) {
                /** @var array<string, mixed>|null $info */
                $info = json_decode((string) file_get_contents($infoFile), true);
                if (is_array($info)) {
                    $title = Cast::str($info['title'] ?? $youtubeId);
                    $description = Cast::str($info['description'] ?? '');
                }
            }

            $hashtags = $this->parseHashtags($title.' '.$description);
            $minioPath = mb_trim(Cast::str(config('youtube_shorts.path_prefix', 'shorts')), '/')."/{$youtubeId}.mp4";

            // 3. Salva no MinIO.
            $stream = fopen($videoFile, 'rb');
            if ($stream === false) {
                return null;
            }
            Storage::disk($this->disk())->put($minioPath, $stream);
            fclose($stream);

            // 4. Persiste no banco.
            YoutubeShort::query()->create([
                'youtube_id' => $youtubeId,
                'title' => $title,
                'description' => $description,
                'hashtags' => $hashtags,
                'minio_path' => $minioPath,
                'downloaded_at' => now(),
            ]);

            return [
                'youtube_id' => $youtubeId,
                'title' => $title,
                'description' => $description,
                'hashtags' => $hashtags,
                'minio_path' => $minioPath,
            ];
        } catch (Throwable $e) {
            Log::error('[YoutubeChannelService] Erro inesperado ao baixar Short.', [
                'youtube_id' => $youtubeId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $this->cleanup($tmpDir);
        }
    }

    /**
     * Gera os jobs de postagem para os Shorts informados, distribuindo-os
     * nos próximos slots disponíveis (respeitando o limite diário).
     *
     * @param  Collection<int, YoutubeShort>  $shorts
     */
    public function scheduleShorts(Collection $shorts): void
    {
        foreach ($shorts as $short) {
            $slot = $this->nextAvailableSlot();

            $job = YoutubeShortJob::query()->create([
                'youtube_short_id' => $short->id,
                'scheduled_at' => $slot,
                'status' => YoutubeShortJob::STATUS_PENDING,
                'discord_notified' => false,
            ]);

            // Enfileira a postagem com atraso até o horário agendado.
            YoutubePostJob::dispatch($job->id)->delay($slot);
        }
    }

    /**
     * Extrai hashtags (#tag) de um texto.
     *
     * @return array<int, string>
     */
    public function parseHashtags(string $text): array
    {
        preg_match_all('/#[\p{L}0-9_]+/u', $text, $matches);

        /** @var array<int, string> $tags */
        $tags = array_values(array_unique($matches[0]));

        return $tags;
    }

    /**
     * Encontra o próximo horário livre respeitando os horários permitidos
     * e o limite de posts por dia.
     */
    private function nextAvailableSlot(): CarbonImmutable
    {
        $allowedHours = Cast::arr(config('youtube_shorts.allowed_hours', []));
        sort($allowedHours);
        $maxPerDay = Cast::int(config('youtube_shorts.max_per_day', 5));

        $now = CarbonImmutable::now();
        $day = $now->startOfDay();

        // Limita a busca a um horizonte razoável (60 dias).
        for ($i = 0; $i < 60; $i++) {
            $cursor = $day->addDays($i);

            $postsThatDay = YoutubeShortJob::query()
                ->whereBetween('scheduled_at', [$cursor->startOfDay(), $cursor->endOfDay()])
                ->count();

            if ($postsThatDay >= $maxPerDay) {
                continue;
            }

            $remaining = $maxPerDay - $postsThatDay;
            $picked = 0;

            foreach ($allowedHours as $hour) {
                if ($picked >= $remaining) {
                    break;
                }

                $slot = $cursor->setTime(Cast::int($hour), 0);

                // Só horários futuros.
                if ($slot->lessThanOrEqualTo($now)) {
                    continue;
                }

                $taken = YoutubeShortJob::query()
                    ->where('scheduled_at', $slot->format('Y-m-d H:i:s'))
                    ->exists();

                if ($taken) {
                    continue;
                }

                return $slot;
            }
        }

        // Fallback improvável: agenda para a próxima hora permitida amanhã.
        return $now->addDay()->setTime(Cast::int($allowedHours[0] ?? 9), 0);
    }

    private function normalizeShortsUrl(string $channelUrl): string
    {
        $url = mb_rtrim(mb_trim($channelUrl), '/');

        if (Str::endsWith($url, '/shorts')) {
            return $url;
        }

        return $url.'/shorts';
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir.'/*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    private function bin(): string
    {
        return Cast::str(config('youtube_shorts.yt_dlp_bin', 'yt-dlp'));
    }

    private function disk(): string
    {
        return Cast::str(config('youtube_shorts.disk', 'minio'));
    }
}
