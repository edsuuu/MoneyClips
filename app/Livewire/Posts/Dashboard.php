<?php

declare(strict_types=1);

namespace App\Livewire\Posts;

use App\Jobs\PublishScheduledPostJob;
use App\Models\ScheduledPost;
use App\Services\SocialPublishing\SocialPublisherRegistry;
use Flux\Flux;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Dashboard de publicações: o que foi postado, o que falhou, o que está
 * pendente, com logs por post e ações de reprocessar/cancelar.
 */
final class Dashboard extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $platformFilter = '';

    /** Post cujo painel de logs está aberto. */
    public ?int $openLogsFor = null;

    public function toggleLogs(int $postId): void
    {
        $this->openLogsFor = $this->openLogsFor === $postId ? null : $postId;
    }

    /** Reenfileira um post (falhado ou agendado) para publicar agora. */
    public function retry(int $postId): void
    {
        $post = ScheduledPost::query()->find($postId);
        if (! $post instanceof ScheduledPost) {
            return;
        }

        if (in_array($post->status, [ScheduledPost::STATUS_POSTED, ScheduledPost::STATUS_PUBLISHING], true)) {
            Flux::toast('Este post não pode ser reprocessado agora.', variant: 'danger');

            return;
        }

        $post->update(['status' => ScheduledPost::STATUS_PUBLISHING, 'error_message' => null]);
        $post->log('info', 'Reprocessamento manual solicitado.');
        dispatch(new PublishScheduledPostJob($post->id));

        Flux::toast('Post reenviado para publicação.');
    }

    public function cancel(int $postId): void
    {
        $post = ScheduledPost::query()->find($postId);
        if (! $post instanceof ScheduledPost) {
            return;
        }

        if ($post->status === ScheduledPost::STATUS_POSTED) {
            Flux::toast('Não é possível cancelar um post já publicado.', variant: 'danger');

            return;
        }

        $post->update(['status' => ScheduledPost::STATUS_CANCELLED]);
        $post->log('warning', 'Agendamento cancelado manualmente.');

        Flux::toast('Agendamento cancelado.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingPlatformFilter(): void
    {
        $this->resetPage();
    }

    public function render(SocialPublisherRegistry $registry): View
    {
        $base = ScheduledPost::query();
        $statuses = ['pending', 'scheduled', 'publishing', 'posted', 'failed', 'cancelled'];

        $counts = [];
        foreach ($statuses as $s) {
            $counts[$s] = (clone $base)->where('status', $s)->count();
        }

        $counts['total'] = (clone $base)->count();

        $query = ScheduledPost::query()->with(['video', 'cut', 'account', 'logs']);

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->platformFilter !== '') {
            $query->where('platform', $this->platformFilter);
        }

        $posts = $query->latest()->paginate(20);

        return view('livewire.posts.dashboard', [
            'posts' => $posts,
            'counts' => $counts,
            'platformLabels' => $registry->labels(),
        ]);
    }
}
