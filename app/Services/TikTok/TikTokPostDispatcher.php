<?php

declare(strict_types=1);

namespace App\Services\TikTok;

use App\Models\TiktokPost;
use App\Models\YoutubeShort;
use RuntimeException;

/**
 * Sorteia um Short do estoque e enfileira a postagem no TikTok.
 *
 * Fonte do estoque: tabela local youtube_shorts (alimentada pelo webhook do
 * microserviço download-shorts). Os já postados/enfileirados são excluídos
 * consultando o ledger tiktok_posts. Sem candidatos, cai no modo legado do
 * uploader (sorteio das pastas antigas do bucket, controladas pelo
 * uploaded_ids.json de lá).
 */
final readonly class TikTokPostDispatcher
{
    public function __construct(private TikTokUploaderClient $uploader) {}

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

        if (! $candidate instanceof YoutubeShort) {
            // Estoque novo esgotado — tenta o estoque legado (pastas no bucket).
            return [
                'job_id' => $this->uploader->postNext(),
                'title' => null,
                'source' => 'legado',
            ];
        }

        $title = (string) ($candidate->title) ?: $candidate->youtube_id;
        $hashtags = array_values(array_filter(
            (array) ($candidate->hashtags ?? []),
            is_string(...),
        ));

        $jobId = $this->uploader->createPost(
            (string) ($candidate->video_path),
            $title,
            $hashtags,
            $candidate->youtube_id,
        );

        return [
            'job_id' => $jobId,
            'title' => (string) ($candidate->title) ?: null,
            'source' => 'estoque',
        ];
    }

    /**
     * Sorteia um Short baixado que ainda não foi postado nem está na fila.
     */
    private function pickCandidate(): ?YoutubeShort
    {
        /** @var list<string> $blocked */
        $blocked = TiktokPost::query()
            ->whereIn('status', TiktokPost::ACTIVE_STATUSES)
            ->whereNotNull('youtube_id')
            ->pluck('youtube_id')
            ->all();

        return YoutubeShort::query()
            ->whereNotNull('video_path')
            ->when($blocked !== [], fn ($q) => $q->whereNotIn('youtube_id', $blocked))
            ->inRandomOrder()
            ->first();
    }
}
