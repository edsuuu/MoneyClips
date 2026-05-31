<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Models\File;
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
                $video->processingJobs()->delete();
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
            ->latest()
            ->get();

        $cards = $videos->map(function (Video $video): array {
            $legendado = $video->files->firstWhere('type', 'legendado');
            $original = $video->files->firstWhere('type', 'original');
            $playable = $legendado instanceof File ? $legendado : ($original instanceof File ? $original : null);

            return [
                'video' => $video,
                'thumb' => $this->resolveTemporaryUrl($playable),
            ];
        });

        return view('livewire.videos.index', [
            'cards' => $cards,
        ]);
    }

    private function resolveTemporaryUrl(?File $file): ?string
    {
        if (! $file instanceof File) {
            return null;
        }

        try {
            return $file->temporaryUrl(120);
        } catch (Throwable) {
            return null;
        }
    }
}
