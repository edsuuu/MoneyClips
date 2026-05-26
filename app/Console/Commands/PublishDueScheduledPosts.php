<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PublishScheduledPostJob;
use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pega os posts agendados cujo horário já chegou, marca como "publishing" e
 * enfileira a publicação real. Roda a cada minuto pelo scheduler.
 */
final class PublishDueScheduledPosts extends Command
{
    protected $signature = 'social:publish-due';

    protected $description = 'Enfileira a publicação dos posts agendados cujo horário já chegou.';

    public function handle(): int
    {
        /** @var Collection<int, int> $dueIds */
        $dueIds = DB::transaction(function (): Collection {
            $posts = ScheduledPost::query()
                ->due()
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            foreach ($posts as $post) {
                $post->update(['status' => ScheduledPost::STATUS_PUBLISHING]);
            }

            return $posts->pluck('id');
        });

        if ($dueIds->isEmpty()) {
            $this->info('Nenhum post pronto para publicar.');

            return self::SUCCESS;
        }

        foreach ($dueIds as $postId) {
            try {
                dispatch(new PublishScheduledPostJob($postId));
            } catch (Throwable $throwable) {
                ScheduledPost::query()
                    ->whereKey($postId)
                    ->where('status', ScheduledPost::STATUS_PUBLISHING)
                    ->update(['status' => ScheduledPost::STATUS_SCHEDULED]);

                throw $throwable;
            }
        }

        $this->info(sprintf('Enfileirados %s post(s) para publicação.', $dueIds->count()));

        return self::SUCCESS;
    }
}
