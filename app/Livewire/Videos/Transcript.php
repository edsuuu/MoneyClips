<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Models\File;
use App\Models\ProcessingJob;
use App\Models\Status;
use App\Models\Transcript as TranscriptModel;
use App\Models\Video;
use App\Models\VideoPayload;
use App\Services\Status\StatusService;
use App\Support\Cast;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

final class Transcript extends Component
{
    public Video $video;

    public string $text = '';

    public bool $loaded = false;

    public function mount(Video $video): void
    {
        $this->video = $video;
        $this->loadTranscriptText();
    }

    /** Polling enquanto a transcrição não chega do Python. */
    public function refreshStatus(): void
    {
        $this->video->refresh();
        if (! $this->loaded) {
            $this->loadTranscriptText();
        }
    }

    public function confirm(StatusService $status): void
    {
        $transcript = $this->video->transcript;
        abort_if($transcript === null, 422, 'Transcrição ainda não disponível.');

        $transcript->update([
            'edited_text' => $this->text,
            'active_text_source' => 'edited',
            'is_confirmed_by_user' => true,
            'confirmed_at' => now(),
        ]);

        $status->transition($this->video, 'transcript_confirmed', 'Transcrição confirmada pelo usuário');
        $status->transition($this->video, 'waiting_cuts', 'Transcrição pronta para criação manual de cortes');

        $this->redirectRoute('videos.editor', ['video' => $this->video->uuid], navigate: true);
    }

    public function saveTranscript(string $text): void
    {
        $transcript = $this->video->transcript;
        abort_if(! $transcript instanceof TranscriptModel, 422, 'Nenhuma transcrição encontrada para este vídeo.');

        $transcript->update([
            'edited_text' => $text,
            'active_text_source' => 'edited',
        ]);

        $this->text = $text;
    }

    /**
     * @param  array<int, array{text: string, start: float|int, end: float|int}>  $words
     */
    public function saveTimedWords(array $words): void
    {
        abort_if($words === [], 422, 'A transcrição por tempo não pode estar vazia.');

        $payload = $this->video->payloads()->firstOrCreate(
            ['type' => 'transcript_validated'],
            ['payload' => []],
        );

        $fullText = Cast::str(collect($words)->pluck('text')->join(' '));
        $lastWord = $words[array_key_last($words)];
        $duration = (float) $lastWord['end'];
        $existingData = $this->payloadData($payload);

        $payload->update([
            'payload' => [
                'language' => isset($existingData['language']) && is_string($existingData['language']) ? $existingData['language'] : 'pt',
                'duration_seconds' => isset($existingData['duration_seconds']) && is_numeric($existingData['duration_seconds'])
                    ? (float) $existingData['duration_seconds']
                    : $duration,
                'text' => $fullText,
                'segments' => [
                    [
                        'start' => (float) $words[0]['start'],
                        'end' => $duration,
                        'text' => $fullText,
                        'words' => array_values($words),
                    ],
                ],
            ],
        ]);

        $transcript = $this->video->transcript;
        if ($transcript instanceof TranscriptModel) {
            $transcript->update([
                'edited_text' => $fullText,
                'active_text_source' => 'edited',
            ]);
            $this->text = $fullText;
        }
    }

    public function render(): View
    {
        $this->video->refresh();

        $job = $this->video->processingJobs()
            ->where('type', 'ingest')->latest()->first();

        $status = $this->video->status;

        $hlsMaster = $this->video->fileOfType('hls_master');
        $legendado = $this->video->fileOfType('legendado');
        $original = $this->video->fileOfType('original');
        $playable = $legendado instanceof File ? $legendado : $original;

        $timedWords = [];
        $payload = $this->video->payloads()
            ->whereIn('type', ['transcript_validated', 'transcript_raw'])
            ->orderByRaw("CASE type WHEN 'transcript_validated' THEN 0 ELSE 1 END")
            ->first();

        if ($payload instanceof VideoPayload) {
            $data = $this->payloadData($payload);

            foreach (Cast::arr($data['segments'] ?? []) as $seg) {
                if (! is_array($seg)) {
                    continue;
                }

                foreach (Cast::arr($seg['words'] ?? []) as $word) {
                    if (! is_array($word)) {
                        continue;
                    }

                    $timedWords[] = [
                        'text' => Cast::str($word['text'] ?? ''),
                        'start' => Cast::float($word['start'] ?? 0),
                        'end' => Cast::float($word['end'] ?? 0),
                    ];
                }
            }
        }

        return view('livewire.videos.transcript', [
            'statusKey' => $status instanceof Status ? $status->key : null,
            'ready' => $this->video->transcript !== null,
            'jobId' => $job instanceof ProcessingJob ? $job->external_job_id : null,
            'wsUrl' => config('video-processor.ws_url'),
            'hlsUrl' => $this->resolveHlsUrl($hlsMaster),
            'playerUrl' => $this->resolvePlayerUrl($playable),
            'timedWords' => $timedWords,
        ]);
    }

    private function loadTranscriptText(): void
    {
        $transcript = $this->video->transcript;
        if ($transcript instanceof TranscriptModel) {
            $this->text = $transcript->activeText() ?? '';
            $this->loaded = true;
        }
    }

    private function resolvePlayerUrl(?File $playable): ?string
    {
        if (! $playable instanceof File) {
            return null;
        }

        try {
            return $playable->temporaryUrl(120);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveHlsUrl(?File $hlsMaster): ?string
    {
        if (! $hlsMaster instanceof File || $hlsMaster->path === '') {
            return null;
        }

        $prefix = 'videos/'.$this->video->uuid.'/hls/';
        $relativePath = str_starts_with($hlsMaster->path, $prefix)
            ? mb_substr($hlsMaster->path, mb_strlen($prefix))
            : basename($hlsMaster->path);

        return route('videos.stream', ['video' => $this->video->uuid, 'path' => $relativePath]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadData(VideoPayload $payload): array
    {
        /** @var array<string, mixed> $data */
        $data = Cast::arr($payload->payload);

        return $data;
    }
}
