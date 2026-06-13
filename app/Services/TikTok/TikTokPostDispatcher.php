<?php

declare(strict_types=1);

namespace App\Services\TikTok;

use App\Models\TiktokPost;
use App\Services\Shorts\ShortsDownloaderClient;
use App\Support\Cast;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Sorteia um Short do estoque e enfileira a postagem no TikTok.
 *
 * Fonte do estoque: o banco do microserviço download-shorts (via API). Os já
 * postados/enfileirados são excluídos consultando o ledger tiktok_posts.
 * Sem candidatos no estoque novo, cai no modo legado do uploader (sorteio
 * das pastas antigas do bucket, controladas pelo uploaded_ids.json de lá).
 */
final readonly class TikTokPostDispatcher
{
    public function __construct(
        private ShortsDownloaderClient $shorts,
        private TikTokUploaderClient $uploader,
    ) {}

    /**
     * Enfileira 1 postagem. Retorna ['job_id' => ..., 'title' => ...,
     * 'source' => 'estoque'|'legado'].
     *
     * @return array{job_id: string, title: string|null, source: string}
     */
    public function dispatchOne(): array
    {
        throw_unless(
            $this->uploader->isHealthy(),
            RuntimeException::class,
            'Microserviço tiktok-uploader indisponível (porta 8780).',
        );

        $candidate = $this->pickCandidate();

        if ($candidate === null) {
            // Estoque novo esgotado — tenta o estoque legado (pastas no bucket).
            return [
                'job_id' => $this->uploader->postNext(),
                'title' => null,
                'source' => 'legado',
            ];
        }

        $jobId = $this->uploader->createPost(
            Cast::str($candidate['storage_path']),
            Cast::str($candidate['title'] ?? '') ?: Cast::str($candidate['youtube_id']),
            array_values(array_filter(Cast::arr($candidate['hashtags'] ?? []), is_string(...))),
            Cast::str($candidate['youtube_id']),
        );

        return [
            'job_id' => $jobId,
            'title' => Cast::str($candidate['title'] ?? '') ?: null,
            'source' => 'estoque',
        ];
    }

    /**
     * Sorteia um item baixado que ainda não foi postado nem está na fila.
     *
     * @return array<string, mixed>|null
     */
    private function pickCandidate(): ?array
    {
        $items = $this->shorts->listItems();

        /** @var list<string> $blocked */
        $blocked = TiktokPost::query()
            ->whereIn('status', TiktokPost::ACTIVE_STATUSES)
            ->whereNotNull('youtube_id')
            ->pluck('youtube_id')
            ->all();
        $blockedSet = array_flip($blocked);

        $candidates = array_values(array_filter(
            $items,
            function (array $item) use ($blockedSet): bool {
                $youtubeId = Cast::str($item['youtube_id'] ?? '');
                $storagePath = Cast::str($item['storage_path'] ?? '');

                return $youtubeId !== ''
                    && $storagePath !== ''
                    && ! isset($blockedSet[$youtubeId]);
            },
        ));

        if ($candidates === []) {
            return null;
        }

        /** @var array<string, mixed> $picked */
        $picked = Arr::random($candidates);

        return $picked;
    }
}
