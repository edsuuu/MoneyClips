<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Models\Status;
use App\Models\Video;
use App\Services\VideoProcessor\VideoProcessorService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

final class Index extends Component
{
    public ?string $pendingDeleteUuid = null;

    public function askDelete(string $videoUuid): void
    {
        $this->pendingDeleteUuid = $videoUuid;
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteUuid = null;
    }

    public function confirmDelete(): void
    {
        if (! is_string($this->pendingDeleteUuid) || $this->pendingDeleteUuid === '') {
            Flux::toast('Selecione um vídeo para excluir.', variant: 'danger');

            return;
        }

        $this->deleteVideo($this->pendingDeleteUuid);
        $this->pendingDeleteUuid = null;
    }

    public function deleteVideo(string $videoUuid): void
    {
        $video = Video::query()
            ->where('uuid', $videoUuid)
            ->first();

        if (! $video instanceof Video) {
            Flux::toast('Vídeo não encontrado.', variant: 'danger');

            return;
        }

        try {
            DB::transaction(function () use ($video): void {
                $video->statusLogs()->delete();
                $video->payloads()->delete();
                $video->transcript()->delete();
                $video->files()->delete();
                $video->cuts()->delete();
                $video->delete();
            });
        } catch (Throwable) {
            Flux::toast('Não foi possível excluir o vídeo.', variant: 'danger');

            return;
        }

        Flux::toast('Vídeo removido com sucesso.');
    }

    /**
     * Dispara o ingest para um vídeo salvo no banco que ainda não foi processado.
     */
    public function process(string $videoUuid, VideoProcessorService $videoProcessor): void
    {
        $video = Video::query()->where('uuid', $videoUuid)->first();

        if (! $video instanceof Video) {
            Flux::toast('Vídeo não encontrado.', variant: 'danger');

            return;
        }

        if ($video->status?->key !== 'pending') {
            Flux::toast('Este vídeo já foi enviado para processamento.', variant: 'danger');

            return;
        }

        try {
            $videoProcessor->startIngest($video);
        } catch (Throwable $throwable) {
            Flux::toast('Falha ao iniciar o processamento: '.$throwable->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast('Processamento iniciado.');
    }

    /**
     * Re-dispara o ingest pra um vídeo que falhou. Reseta progresso, marca como
     * pending e chama startIngest de novo. Os status_logs antigos ficam de
     * trilha de auditoria.
     */
    public function reprocess(string $videoUuid, VideoProcessorService $videoProcessor): void
    {
        $video = Video::query()->where('uuid', $videoUuid)->first();

        if (! $video instanceof Video) {
            Flux::toast('Vídeo não encontrado.', variant: 'danger');

            return;
        }

        if ($video->status?->key !== 'failed') {
            Flux::toast('Só dá pra reprocessar vídeos que falharam.', variant: 'danger');

            return;
        }

        try {
            $video->update([
                'progress' => 0,
                'current_stage' => 'ingest',
                'status_id' => Status::idFor('pending'),
            ]);

            $videoProcessor->startIngest($video);
        } catch (Throwable $throwable) {
            Flux::toast('Falha ao reprocessar: '.$throwable->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast('Reprocessamento disparado.');
    }

    public function render(): View
    {
        /** @var Collection<int, Video> $videos */
        $videos = Video::query()
            ->with(['status', 'files'])
            ->withCount('processingJobs')
            ->latest()
            ->get();

        $cards = $videos->map(fn(Video $video): array => [
            'video' => $video,
            'thumb' => route('videos.thumbnail', $video),
        ]);

        return view('livewire.videos.index', [
            'cards' => $cards,
        ]);
    }
}
