<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Models\Cut;
use App\Models\ProcessingJob;
use App\Models\Status;
use App\Models\Video;
use App\Services\VideoProcessor\VideoProcessorService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

final class Editor extends Component
{
    public Video $video;

    public float $newStart = 0.0;

    public float $newEnd = 60.0;

    public string $userPrompt = '';

    public array $selectedCuts = [];

    /** @var array<string, array{start: float, end: float}> */
    public array $cutEdits = [];

    public function mount(Video $video): void
    {
        $this->video = $video;
    }

    public function refreshStatus(): void
    {
        $this->video->refresh();
    }

    public function addCut(): void
    {
        $this->validate([
            'newStart' => 'required|numeric|min:0',
            'newEnd' => 'required|numeric|gt:newStart',
        ]);

        $maxIndex = $this->video->cuts()->max('index');
        $index = (is_numeric($maxIndex) ? (int) $maxIndex : 0) + 1;

        $this->video->cuts()->create([
            'index' => $index,
            'name' => 'PT'.$index,
            'type' => 'pt'.$index,
            'source' => 'manual',
            'start_seconds' => $this->newStart,
            'end_seconds' => $this->newEnd,
            'duration_seconds' => $this->newEnd - $this->newStart,
            'status_id' => Status::idFor('pending'),
        ]);

        Flux::toast('Corte adicionado.');
    }

    public function updateCut(string $uuid, float $start, float $end): void
    {
        $cut = $this->video->cuts()->where('uuid', $uuid)->firstOrFail();
        $cut->update([
            'start_seconds' => $start,
            'end_seconds'   => $end,
            'duration_seconds' => max(0, $end - $start),
        ]);

        Flux::toast('Corte atualizado.');
    }

    public function deleteCut(string $uuid): void
    {
        $this->video->cuts()->where('uuid', $uuid)->delete();
    }

    public function recommend(VideoProcessorService $videoProcessor): void
    {
        $cuts = $videoProcessor->recommendCuts($this->video, $this->userPrompt ?: null);

        foreach ($cuts as $item) {
            $maxIndexVal = $this->video->cuts()->max('index');
            $maxIndex = is_numeric($maxIndexVal) ? (int) $maxIndexVal : 0;
            $index = isset($item['index']) && is_numeric($item['index']) ? (int) $item['index'] : $maxIndex + 1;
            $this->video->cuts()->updateOrCreate(
                ['video_id' => $this->video->id, 'index' => $index],
                [
                    'name' => $item['name'] ?? 'PT'.$index,
                    'type' => $item['type'] ?? 'pt'.$index,
                    'source' => 'ai',
                    'start_seconds' => $item['start_seconds'],
                    'end_seconds' => $item['end_seconds'],
                    'duration_seconds' => $item['duration_seconds'],
                    'score' => $item['score'] ?? null,
                    'reason' => $item['reason'] ?? null,
                    'status_id' => Status::idFor('pending'),
                ],
            );
        }

        Flux::toast(count($cuts).' cortes recomendados pela IA.');
    }

    public function renderCuts(VideoProcessorService $videoProcessor): void
    {
        /** @var Collection<int, Cut> $cuts */
        $cuts = $this->video->cuts()->get();
        abort_if($cuts->isEmpty(), 422, 'Nenhum corte para renderizar.');

        $videoProcessor->startRenderCuts($this->video, $cuts);
        Flux::toast('Renderização iniciada. Os arquivos aparecem ao concluir.');
    }

    public function renderSelected(): void
    {
        $uuids = $this->selectedCuts;

        if (empty($uuids)) {
            Flux::toast('Selecione ao menos um corte para renderizar.');
            return;
        }

        /** @var Collection<int, Cut> $cuts */
        $cuts = $this->video->cuts()->whereIn('uuid', $uuids)->get();
        abort_if($cuts->isEmpty(), 422, 'Nenhum corte encontrado.');

        /** @var VideoProcessorService $videoProcessor */
        $videoProcessor = app(VideoProcessorService::class);
        $videoProcessor->startRenderCuts($this->video, $cuts);
        Flux::toast(count($uuids).' corte(s) enviado(s) para renderização.');
        $this->reset('selectedCuts');
    }

    public function saveCutEdit(string $uuid): void
    {
        $data = $this->cutEdits[$uuid] ?? null;
        abort_if(! is_array($data), 422, 'Corte inválido.');

        $start = (float) ($data['start'] ?? 0);
        $end = (float) ($data['end'] ?? 0);

        if ($start < 0 || $end <= $start) {
            Flux::toast('Tempos inválidos: o fim deve ser maior que o início.', variant: 'danger');

            return;
        }

        $cut = $this->video->cuts()->where('uuid', $uuid)->firstOrFail();
        $cut->update([
            'start_seconds'    => $start,
            'end_seconds'      => $end,
            'duration_seconds' => max(0, $end - $start),
        ]);

        Flux::toast('Corte atualizado com sucesso.');
    }

    public function deleteSelected(): void
    {
        $uuids = $this->selectedCuts;
        if (empty($uuids)) {
            Flux::toast('Selecione ao menos um corte para apagar.', variant: 'danger');
            return;
        }

        $this->video->cuts()->whereIn('uuid', $uuids)->delete();
        Flux::toast(count($uuids) . ' corte(s) apagado(s).');
        $this->reset('selectedCuts');
    }

    public function saveTranscript(string $text): void
    {
        $transcript = $this->video->transcript;
        if ($transcript) {
            $transcript->update([
                'edited_text'         => $text,
                'active_text_source'  => 'edited',
            ]);
            Flux::toast('Transcrição salva.');
        } else {
            Flux::toast('Nenhuma transcrição encontrada para este vídeo.');
        }
    }

    public function saveTimedWords(array $words): void
    {
        if (empty($words)) {
            Flux::toast('A transcrição não pode estar vazia.', variant: 'danger');
            return;
        }

        $payload = $this->video->payloads()->firstOrCreate(
            ['type' => 'transcript_validated'],
            ['payload' => []]
        );

        $fullText = collect($words)->pluck('text')->join(' ');
        $duration = end($words)['end'];

        $existingData = is_array($payload->payload) ? $payload->payload : json_decode($payload->payload ?: '{}', true);

        $newPayloadData = [
            'language' => $existingData['language'] ?? 'pt',
            'duration_seconds' => $existingData['duration_seconds'] ?? $duration,
            'text' => $fullText,
            'segments' => [
                [
                    'start' => $words[0]['start'],
                    'end' => $duration,
                    'text' => $fullText,
                    'words' => $words,
                ]
            ],
        ];

        $payload->update(['payload' => $newPayloadData]);

        if ($this->video->transcript) {
            $this->video->transcript->update([
                'edited_text'         => $fullText,
                'active_text_source'  => 'edited',
            ]);
        }

        Flux::toast('Sincronia salva e aplicada!', variant: 'success');
    }

    public function render(): View
    {
        $this->video->refresh()->load(['cuts.files', 'files', 'transcript']);

        $legendado = $this->video->fileOfType('legendado');
        $original = $this->video->fileOfType('original');
        $playable = $legendado ?? $original;

        // Job em andamento para mostrar progresso.
        $activeJob = $this->video->processingJobs()
            ->whereHas('status', fn ($q) => $q->whereNotIn('key', ['completed', 'failed']))
            ->latest()
            ->first();

        $status = $this->video->status;

        foreach ($this->video->cuts as $cut) {
            $this->cutEdits[$cut->uuid] ??= [
                'start' => (float) $cut->start_seconds,
                'end'   => (float) $cut->end_seconds,
            ];
        }

        $timedWords = [];
        $payload = $this->video->payloads()
            ->whereIn('type', ['transcript_validated', 'transcript_raw'])
            ->orderByRaw("FIELD(type, 'transcript_validated', 'transcript_raw')")
            ->first();

        if ($payload) {
            $data = is_array($payload->payload) ? $payload->payload : json_decode($payload->payload, true);
            foreach ($data['segments'] ?? [] as $seg) {
                foreach ($seg['words'] ?? [] as $w) {
                    $timedWords[] = [
                        'text'  => $w['text'],
                        'start' => (float) $w['start'],
                        'end'   => (float) $w['end'],
                    ];
                }
            }
        }

        return view('livewire.videos.editor', [
            'cuts'        => $this->video->cuts,
            'playerUrl'   => $this->resolvePlayerUrl($playable),
            'transcript'  => $this->video->transcript,
            'timedWords'  => $timedWords,
            'statusKey'   => $status instanceof Status ? $status->key : null,
            'activeJobId' => $activeJob instanceof ProcessingJob ? $activeJob->external_job_id : null,
            'wsUrl'       => config('video-processor.ws_url'),
        ]);
    }

    private function resolvePlayerUrl(mixed $playable): ?string
    {
        if ($playable === null) {
            return null;
        }

        try {
            return $playable->temporaryUrl(120);
        } catch (\Throwable) {
            return null;
        }
    }
}
