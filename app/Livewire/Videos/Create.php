<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Jobs\RunAutoPilotJob;
use App\Models\Status;
use App\Models\Transcript;
use App\Models\Video;
use App\Services\Status\StatusService;
use App\Services\VideoProcessor\VideoProcessorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

final class Create extends Component
{
    #[Validate('required|url')]
    public string $url = '';

    /** Modo de processamento na tela de criação. */
    #[Validate('required|in:manual,sequential,ai')]
    public string $processingMode = 'manual';

    /** Quantidade-alvo de clipes (opcional). Vazio = automático. */
    #[Validate('nullable|integer|min:1|max:60')]
    public ?int $clipCount = null;

    /** Seguir o rosto (crop dinâmico). Desligue para screencast, animação, etc. */
    #[Validate('boolean')]
    public bool $faceTracking = true;

    public function start(VideoProcessorService $videoProcessor): void
    {
        $this->validate([
            'url' => 'required|url',
            'processingMode' => 'required|in:manual,sequential,ai',
            'clipCount' => 'nullable|integer|min:1|max:60',
            'faceTracking' => 'boolean',
        ]);

        $isAuto = $this->isAutoMode();
        $autoMode = $this->resolvedAutoMode();
        $autoClipCount = $this->resolvedAutoClipCount();

        $existingVideo = Video::query()->where('url', $this->url)
            ->whereNotIn('status_id', [
                Status::idFor('failed'),
                Status::idFor('pending'),
                Status::idFor('queued'),
                Status::idFor('processing'),
                Status::idFor('downloading'),
            ])
            ->whereHas('files', fn ($q) => $q->where('type', 'original'))
            ->whereHas('transcript')
            ->latest()
            ->first();

        if ($existingVideo) {
            $video = DB::transaction(function () use ($existingVideo, $isAuto, $autoMode, $autoClipCount) {
                $hasLegendado = $existingVideo->fileOfType('legendado') !== null;
                $statusKey = $hasLegendado ? 'full_subtitled' : 'waiting_transcript_review';

                $newVideo = Video::query()->create([
                    'url' => $this->url,
                    'status_id' => Status::idFor($statusKey),
                    'progress' => 100,
                    'created_by' => Auth::id(),
                    'title' => $existingVideo->title,
                    'duration_seconds' => $existingVideo->duration_seconds,
                    'source_provider' => $existingVideo->source_provider,
                    'external_video_id' => $existingVideo->external_video_id,
                    'current_stage' => $hasLegendado ? 'subtitle_full' : 'ingest',
                    'is_auto' => $isAuto,
                    'auto_mode' => $autoMode,
                    'auto_clip_count' => $autoClipCount,
                    'face_tracking' => $this->faceTracking,
                ]);

                $transcript = $existingVideo->transcript;
                if ($transcript instanceof Transcript) {
                    $newVideo->transcript()->create([
                        'language' => $transcript->language,
                        'duration_seconds' => $transcript->duration_seconds,
                        'raw_text' => $transcript->raw_text,
                        'validated_text' => $transcript->validated_text,
                        'edited_text' => $transcript->edited_text,
                        'active_text_source' => $transcript->active_text_source,
                        'is_validated_by_ai' => $transcript->is_validated_by_ai,
                    ]);
                }

                foreach ($existingVideo->files as $file) {
                    if ($file->cut_id === null) {
                        $newVideo->files()->create([
                            'type' => $file->type,
                            'disk' => $file->disk,
                            'bucket' => $file->bucket,
                            'path' => $file->path,
                            'mime_type' => $file->mime_type,
                            'extension' => $file->extension,
                            'size_bytes' => $file->size_bytes,
                            'checksum_sha256' => $file->checksum_sha256,
                        ]);
                    }
                }

                foreach ($existingVideo->payloads as $payload) {
                    $newVideo->payloads()->create([
                        'type' => $payload->type,
                        'payload' => $payload->payload,
                    ]);
                }

                $statusService = resolve(StatusService::class);
                $statusService->transition($newVideo, 'pending', 'Vídeo criado via cache de URL existente');
                $statusService->transition($newVideo, $statusKey, 'Dados recuperados do cache da URL');

                return $newVideo;
            });

            if ($isAuto) {
                // Transcrição já está em cache: o piloto automático segue direto para cortes/render.
                dispatch(new RunAutoPilotJob($video->id));
                $this->redirectRoute('videos.editor', ['video' => $video->uuid], navigate: true);
            } elseif ($video->fileOfType('legendado') !== null) {
                $this->redirectRoute('videos.editor', ['video' => $video->uuid], navigate: true);
            } else {
                $this->redirectRoute('videos.transcript', ['video' => $video->uuid], navigate: true);
            }

            return;
        }

        $video = Video::query()->create([
            'url' => $this->url,
            'status_id' => Status::idFor('pending'),
            'progress' => 0,
            'created_by' => Auth::id(),
            'is_auto' => $isAuto,
            'auto_mode' => $autoMode,
            'auto_clip_count' => $autoClipCount,
            'face_tracking' => $this->faceTracking,
        ]);

        $videoProcessor->startIngest($video);

        // No modo automático o piloto dispara sozinho quando o callback de ingestão chega;
        // levamos o usuário ao editor para acompanhar os cortes sendo gerados/renderizados.
        $this->redirectRoute(
            $isAuto ? 'videos.editor' : 'videos.transcript',
            ['video' => $video->uuid],
            navigate: true,
        );
    }

    public function updatedProcessingMode(string $value): void
    {
        if ($value !== 'ai') {
            $this->clipCount = null;
        }
    }

    public function render(): View
    {
        return view('livewire.videos.create', [
            'recent' => Video::with('status')->latest()->limit(10)->get(),
        ]);
    }

    private function isAutoMode(): bool
    {
        return in_array($this->processingMode, ['sequential', 'ai'], true);
    }

    private function resolvedAutoMode(): string
    {
        return match ($this->processingMode) {
            'sequential' => 'sequential',
            'ai' => 'ai',
            default => 'auto',
        };
    }

    private function resolvedAutoClipCount(): ?int
    {
        return $this->processingMode === 'ai' ? $this->clipCount : null;
    }
}
