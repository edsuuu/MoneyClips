<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use App\Services\TiktokPost\TiktokPostService;
use App\Services\Youtube\ShortsPoster;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Auto-postagem unificada SEM jobs/commands: sorteia 1 Short do estoque (fonte
 * única youtube_shorts), reserva (não-repetição) e publica o MESMO vídeo no
 * YouTube (Data API, SÍNCRONO via ShortsPoster) e no TikTok (microserviço
 * uploader, assíncrono via webhook).
 *
 * Agendado em routes/console.php com Schedule::call() em 5 janelas/dia, cada
 * uma num MINUTO ALEATÓRIO estável por dia (ver isDueWindow/minuteFor) — sem
 * persistir estado: o seed (data + hora da janela) faz toda execução do
 * scheduler concordar no mesmo minuto.
 */
final readonly class AutoPostDispatcher
{
    /** Início de cada janela de 1h (hora cheia, fuso São Paulo). */
    public const array WINDOWS = [9, 12, 15, 18, 21];

    public const string TIMEZONE = 'America/Sao_Paulo';

    /** Quantos candidatos sortear por busca ao procurar um com arquivo no storage. */
    private const int PROBE_LIMIT = 50;

    public function __construct(
        private ShortsPoster $poster,
        private TiktokPostService $tiktok,
        private DiscordNotifier $discord,
    ) {}

    /**
     * True se AGORA é o minuto sorteado de alguma janela do dia. Usado no
     * ->when() do scheduler para liberar 1x por janela.
     */
    public static function isDueWindow(?CarbonInterface $now = null): bool
    {
        $now ??= Date::now(self::TIMEZONE);

        return array_any(self::WINDOWS, fn (int $hour): bool => $now->hour === $hour && $now->minute === self::minuteFor($now, $hour));
    }

    /** Minuto sorteado (0–59), estável para o dia e a janela informados. */
    public static function minuteFor(CarbonInterface $day, int $hour): int
    {
        return abs(crc32($day->format('Y-m-d').':'.$hour)) % 60;
    }

    /**
     * Sorteia/reserva N Shorts e publica cada um no YouTube + TikTok.
     * Default de N: youtube_shorts.posting.posts_per_run (1).
     */
    public function run(?int $count = null): void
    {
        $youtubeEnabled = (bool) config('youtube_shorts.posting.youtube_enabled', true);
        $tiktokEnabled = (bool) config('youtube_shorts.posting.tiktok_enabled', true);

        if (! $youtubeEnabled && ! $tiktokEnabled) {
            Log::warning('[AutoPost] YouTube e TikTok desativados — nada a postar.');

            return;
        }

        $count = max(1, $count ?? (int) config('youtube_shorts.posting.posts_per_run', 1));

        for ($i = 0; $i < $count; $i++) {
            $short = $this->reserveNext();
            if (! $short instanceof YoutubeShort) {
                Log::warning('[AutoPost] Nenhum Short disponível para postar.');
                break;
            }

            if ($youtubeEnabled) {
                $this->postYoutube($short);
            }

            if ($tiktokEnabled) {
                $this->queueTiktok($short);
            }
        }

        $this->warnIfLowStock();
    }

    /** YouTube: upload SÍNCRONO via Data API. Marca posted_youtube_at no sucesso. */
    private function postYoutube(YoutubeShort $short): void
    {
        try {
            $videoId = $this->poster->post($short);
            $link = 'https://www.youtube.com/shorts/'.$videoId;
            Log::info('[AutoPost] Short postado no YouTube.', ['id' => $short->id, 'link' => $link]);
            $this->discord->success(
                '✅ Short postado no YouTube',
                ($short->title ?? $short->youtube_id).PHP_EOL.$link,
                $link,
            );
        } catch (Throwable $throwable) {
            Log::error('[AutoPost] Falha ao postar no YouTube.', ['id' => $short->id, 'error' => $throwable->getMessage()]);
            $this->discord->error(
                '❌ Falha ao postar Short no YouTube',
                'Short ID: '.$short->id.PHP_EOL.('Erro: '.$throwable->getMessage()),
            );
        }
    }

    /** TikTok: enfileira no uploader; o callback grava posted_tiktok_at no sucesso. */
    private function queueTiktok(YoutubeShort $short): void
    {
        try {
            $this->tiktok->queuePost(
                $short->youtube_id,
                $short->title ?? $short->youtube_id,
                $short->hashtags ?? [],
                $short->video_path,
            );
        } catch (Throwable $throwable) {
            Log::error('[AutoPost] Falha ao enfileirar no TikTok.', ['id' => $short->id, 'error' => $throwable->getMessage()]);
            $this->discord->error(
                '❌ Falha ao enfileirar no TikTok',
                ($short->title ?? $short->youtube_id).PHP_EOL.$throwable->getMessage(),
            );
        }
    }

    /**
     * Sorteia e RESERVA o próximo Short disponível CUJO ARQUIVO existe no
     * storage. Pula "fantasmas" (linha com video_path mas sem objeto no MinIO).
     * A reserva é atômica (update condicional em dispatched_at).
     */
    private function reserveNext(): ?YoutubeShort
    {
        $disk = Storage::disk((string) config('youtube_shorts.disk', 'minio'));

        $candidates = YoutubeShort::query()
            ->availableToPost()
            ->inRandomOrder()
            ->limit(self::PROBE_LIMIT)
            ->get();

        foreach ($candidates as $short) {
            $path = (string) $short->video_path;
            if ($path === '') {
                continue;
            }

            if (! $disk->exists($path)) {
                continue;
            }

            $reserved = YoutubeShort::query()
                ->whereKey($short->id)
                ->whereNull('dispatched_at')
                ->update(['dispatched_at' => now()]);

            if ($reserved === 1) {
                return $short->refresh();
            }
        }

        return null;
    }

    /** Avisa no Discord 1x/dia quando o estoque disponível cai até o limiar. */
    private function warnIfLowStock(): void
    {
        $total = YoutubeShort::query()->whereNotNull('video_path')->count();
        if ($total === 0) {
            return;
        }

        $remaining = YoutubeShort::query()->availableToPost()->count();
        $threshold = (float) config('youtube_shorts.posting.low_stock_threshold', 0.20);

        if ($remaining / $total > $threshold) {
            return;
        }

        // Só avisa uma vez por dia.
        if (! Cache::add('social:low-stock-warned', true, now()->endOfDay())) {
            return;
        }

        $percent = (int) round(($remaining / $total) * 100);
        $this->discord->warning(
            '⚠️ Estoque de Shorts baixo',
            sprintf('Restam %s de %d Shorts (%d%%) para postar.', $remaining, $total, $percent).PHP_EOL.
            'Baixe mais com: php artisan youtube:download-shorts "<url-do-canal>"',
        );
    }
}
