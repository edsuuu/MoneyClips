<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PublishScheduledPostJob;
use App\Models\ScheduledPost;
use Illuminate\Console\Command;

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
        $due = ScheduledPost::query()->due()->orderBy('sequence')->get();

        if ($due->isEmpty()) {
            $this->info('Nenhum post pronto para publicar.');

            return self::SUCCESS;
        }

        foreach ($due as $post) {
            // Marca como publishing antes de enfileirar para não despachar duas vezes.
            $post->update(['status' => ScheduledPost::STATUS_PUBLISHING]);
            dispatch(new PublishScheduledPostJob($post->id));
        }

        $this->info(sprintf('Enfileirados %s post(s) para publicação.', $due->count()));

        return self::SUCCESS;
    }
}
