<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Models\Status;
use App\Models\Transcript;
use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

final class Create extends Component
{
    #[Validate('required|url')]
    public string $url = '';

    public function start(): void
    {
        $this->validate();

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
            $video = DB::transaction(function () use ($existingVideo) {
                $hasLegendado = $existingVideo->fileOfType('legendado') !== null;
                $statusKey = $hasLegendado ? 'full_subtitled' : 'waiting_cuts';

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
                    'face_tracking' => (bool) ($existingVideo->face_tracking ?? true),
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

                return $newVideo;
            });

            $this->redirectRoute('videos.editor', ['video' => $video->uuid], navigate: true);

            return;
        }

        $video = Video::query()->create([
            'url' => $this->url,
            'status_id' => Status::idFor('pending'),
            'progress' => 0,
            'created_by' => Auth::id(),
        ]);

        $this->redirectRoute('videos.editor', ['video' => $video->uuid], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.videos.create');
    }
}
