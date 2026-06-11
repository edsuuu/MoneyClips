<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Jobs\ProcessVideoJob;
use App\Jobs\PublishScheduledPostJob;
use App\Models\Cut;
use App\Models\File;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\Status;
use App\Models\Video;
use App\Models\VideoPayload;
use App\Services\SocialPublishing\PostDraftBuilder;
use App\Services\VideoProcessor\VideoProcessorService;
use App\Support\Cast;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

final class Editor extends Component
{
    /** Status que ainda não terminaram — mantém polling ativo. */
    private const array ACTIVE_STATUSES = [
        'queued', 'downloading', 'processing', 'transcribing',
        'subtitling_full', 'cutting', 'recommending_cuts',
    ];

    public Video $video;

    public float $newStart = 0.0;

    public float $newEnd = 60.0;

    public string $pendingAutoGenerationMode = '';

    /** @var list<string> */
    public array $selectedCuts = [];

    /** @var array<string, array{start: float, end: float}> */
    public array $cutEdits = [];

    public string $quickYoutubeAccount = '';

    /**
     * external_job_id da renderização que o usuário acabou de iniciar.
     * Guardamos explicitamente para a barra de progresso aparecer no mesmo
     * ciclo do clique — sem depender de a query "achar" um job não-terminal
     * (corrida que se perde em renders rápidos / jobs antigos travados).
     */
    public ?string $renderJobId = null;

    public function mount(string $uuid): void
    {
        $this->video = Video::query()->where('uuid', $uuid)->firstOrFail();
    }

    public function refreshStatus(): void
    {
        $this->video->refresh();

        $this->renderJobId = null;
    }

    public function isProcessingActive(): bool
    {
        return in_array($this->video->status?->key, self::ACTIVE_STATUSES, true);
    }

    public function startDownload(): void
    {
        $this->video->refresh();

        dispatch(new ProcessVideoJob($this->video));

        Flux::toast('Download iniciado.');
    }

    public function processVideo(VideoProcessorService $videoProcessor): void
    {
        $this->video->refresh();

        if ($this->video->status?->key !== 'pending') {
            Flux::toast('Este vídeo já foi enviado para processamento.', variant: 'danger');

            return;
        }

        try {
            $videoProcessor->startIngest($this->video);
            $this->video->refresh();
        } catch (Throwable $throwable) {
            Flux::toast('Falha ao iniciar o processamento: '.$throwable->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast('Processamento iniciado.');
    }

    public function reprocessVideo(VideoProcessorService $videoProcessor): void
    {
        $this->video->refresh();

        if ($this->video->status?->key !== 'failed') {
            Flux::toast('Só dá pra reprocessar vídeos que falharam.', variant: 'danger');

            return;
        }

        try {
            $this->video->update([
                'progress' => 0,
                'status_id' => Status::idFor('pending'),
            ]);

            $videoProcessor->startIngest($this->video);
            $this->video->refresh();
        } catch (Throwable $throwable) {
            Flux::toast('Falha ao reprocessar: '.$throwable->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast('Reprocessamento disparado.');
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
            'end_seconds' => $end,
            'duration_seconds' => max(0, $end - $start),
        ]);

        Flux::toast('Corte atualizado.');
    }

    public function deleteCut(string $uuid): void
    {
        $this->video->cuts()->where('uuid', $uuid)->delete();
    }

    public function selectAutoGenerationMode(string $mode): void
    {
        if (! in_array($mode, ['ai', 'timed'], true)) {
            return;
        }

        $this->pendingAutoGenerationMode = $mode;
    }

    public function confirmAutoGeneration(VideoProcessorService $videoProcessor): void
    {
        if ($this->pendingAutoGenerationMode === 'ai') {
            $this->generateAiCuts($videoProcessor);
            $this->pendingAutoGenerationMode = '';

            return;
        }

        if ($this->pendingAutoGenerationMode === 'timed') {
            $this->generateTimedCuts();
            $this->pendingAutoGenerationMode = '';

            return;
        }

        Flux::toast('Escolha uma opção antes de confirmar.', variant: 'danger');
    }

    public function generateAiCuts(VideoProcessorService $videoProcessor): void
    {
        try {
            $cuts = $videoProcessor->recommendCuts(
                $this->video,
                'Gere cortes consecutivos e contínuos, sem saltos grandes entre um trecho e o seguinte. Cada corte deve começar imediatamente após o fim do corte anterior, preservando o contexto da fala e evitando pular para partes muito distantes do vídeo.',
                [
                    'min_cuts' => 3,
                    'max_cuts' => 12,
                    'prefer_contiguous' => true,
                    'max_gap_seconds' => 0,
                ],
            );
        } catch (Throwable $throwable) {
            Flux::toast('Falha ao sugerir cortes com IA: '.$throwable->getMessage(), variant: 'danger');

            return;
        }

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

    public function generateTimedCuts(): void
    {
        $duration = $this->resolveVideoDuration();

        if ($duration <= 0) {
            Flux::toast('Não foi possível identificar a duração do vídeo.', variant: 'danger');

            return;
        }

        $clipSeconds = max(1, Cast::int(config('video-processor.auto.clip_seconds', 60)));
        $segments = [];
        $start = 0.0;
        $index = 0;

        while ($start < $duration - 0.5) {
            $index++;
            $end = min($start + $clipSeconds, $duration);

            $segments[] = [
                'index' => $index,
                'start_seconds' => $start,
                'end_seconds' => $end,
                'duration_seconds' => $end - $start,
            ];

            $start = $end;
        }

        foreach ($segments as $segment) {
            $this->video->cuts()->updateOrCreate(
                ['video_id' => $this->video->id, 'index' => $segment['index']],
                [
                    'name' => 'PT'.$segment['index'],
                    'type' => 'pt'.$segment['index'],
                    'source' => 'auto',
                    'start_seconds' => $segment['start_seconds'],
                    'end_seconds' => $segment['end_seconds'],
                    'duration_seconds' => $segment['duration_seconds'],
                    'status_id' => Status::idFor('pending'),
                ],
            );
        }

        Flux::toast(count($segments).' cortes gerados por tempo.');
    }

    public function renderCuts(VideoProcessorService $videoProcessor): void
    {
        /** @var Collection<int, Cut> $cuts */
        $cuts = $this->video->cuts()->get();
        abort_if($cuts->isEmpty(), 422, 'Nenhum corte para renderizar.');

        $job = $videoProcessor->startRenderCuts($this->video, $cuts);
        $this->renderJobId = $job->external_job_id;
        Flux::toast('Renderização iniciada. Os arquivos aparecem ao concluir.');
    }

    public function renderSelected(): void
    {
        $uuids = $this->selectedCuts;

        if ($uuids === []) {
            Flux::toast('Selecione ao menos um corte para renderizar.');

            return;
        }

        /** @var Collection<int, Cut> $cuts */
        $cuts = $this->video->cuts()->whereIn('uuid', $uuids)->get();
        abort_if($cuts->isEmpty(), 422, 'Nenhum corte encontrado.');

        /** @var VideoProcessorService $videoProcessor */
        $videoProcessor = resolve(VideoProcessorService::class);
        $job = $videoProcessor->startRenderCuts($this->video, $cuts);
        $this->renderJobId = $job->external_job_id;
        Flux::toast(count($uuids).' corte(s) enviado(s) para renderização.');
        $this->reset('selectedCuts');
    }

    public function saveCutEdit(string $uuid): void
    {
        $data = $this->cutEdits[$uuid] ?? null;
        abort_if(! is_array($data), 422, 'Corte inválido.');

        $start = (float) $data['start'];
        $end = (float) $data['end'];

        if ($start < 0 || $end <= $start) {
            Flux::toast('Tempos inválidos: o fim deve ser maior que o início.', variant: 'danger');

            return;
        }

        $cut = $this->video->cuts()->where('uuid', $uuid)->firstOrFail();
        $cut->update([
            'start_seconds' => $start,
            'end_seconds' => $end,
            'duration_seconds' => max(0, $end - $start),
        ]);

        Flux::toast('Corte atualizado com sucesso.');
        $this->dispatch('cut-saved', uuid: $uuid);
    }

    public function deleteSelected(): void
    {
        $uuids = $this->selectedCuts;
        if ($uuids === []) {
            Flux::toast('Selecione ao menos um corte para apagar.', variant: 'danger');

            return;
        }

        $this->video->cuts()->whereIn('uuid', $uuids)->delete();
        Flux::toast(count($uuids).' corte(s) apagado(s).');
        $this->reset('selectedCuts');
    }

    public function saveTranscript(string $text): void
    {
        $transcript = $this->video->transcript;
        if ($transcript) {
            $transcript->update([
                'edited_text' => $text,
                'active_text_source' => 'edited',
            ]);
            Flux::toast('Transcrição salva.');
        } else {
            Flux::toast('Nenhuma transcrição encontrada para este vídeo.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $words
     */
    public function saveTimedWords(array $words): void
    {
        $normalizedWords = $this->normalizeTimedWords($words);

        if ($normalizedWords === []) {
            Flux::toast('A transcrição não pode estar vazia.', variant: 'danger');

            return;
        }

        $payload = $this->video->payloads()->firstOrCreate(
            ['type' => 'transcript_validated'],
            ['payload' => []]
        );

        $fullText = Cast::str(collect($normalizedWords)->pluck('text')->join(' '));
        $lastWord = $normalizedWords[array_key_last($normalizedWords)];
        $duration = (float) $lastWord['end'];

        $existingData = $this->payloadData($payload);

        $newPayloadData = [
            'language' => isset($existingData['language']) && is_string($existingData['language']) ? $existingData['language'] : 'pt',
            'duration_seconds' => $duration,
            'text' => $fullText,
            'segments' => [
                [
                    'start' => $normalizedWords[0]['start'],
                    'end' => $duration,
                    'text' => $fullText,
                    'words' => $normalizedWords,
                ],
            ],
        ];

        $payload->update(['payload' => $newPayloadData]);

        if ($this->video->transcript) {
            $this->video->transcript->update([
                'edited_text' => $fullText,
                'active_text_source' => 'edited',
            ]);
        }

        Flux::toast('Sincronia salva e aplicada!', variant: 'success');
        $this->dispatch('timed-words-saved');
    }

    public function openScheduleForSelected(): void
    {
        $uuids = $this->selectedCuts;
        if ($uuids === []) {
            Flux::toast('Selecione ao menos um corte.', variant: 'danger');

            return;
        }

        $this->redirectRoute('videos.schedule', [
            'video' => $this->video->uuid,
            'cuts' => implode(',', $uuids),
            'platform' => 'youtube',
            'mode' => 'schedule',
        ], navigate: true);
    }

    public function publishSelectedToYoutube(PostDraftBuilder $draftBuilder): void
    {
        $uuids = $this->selectedCuts;
        if ($uuids === []) {
            Flux::toast('Selecione ao menos um corte.', variant: 'danger');

            return;
        }

        $account = $this->resolveQuickYoutubeAccount();
        if (! $account instanceof SocialAccount) {
            Flux::toast('Conecte uma conta do YouTube antes de publicar.', variant: 'danger');

            return;
        }

        /** @var Collection<int, Cut> $cuts */
        $cuts = $this->video->cuts()
            ->whereIn('uuid', $uuids)
            ->orderBy('index')
            ->get();

        if ($cuts->isEmpty()) {
            Flux::toast('Nenhum corte valido selecionado.', variant: 'danger');

            return;
        }

        foreach ($cuts as $index => $cut) {
            $draft = $draftBuilder->forCut($this->video, $cut);

            $post = ScheduledPost::query()->create([
                'video_id' => $this->video->id,
                'cut_id' => $cut->id,
                'social_account_id' => $account->id,
                'platform' => 'youtube',
                'sequence' => $index + 1,
                'title' => $draft['title'],
                'description' => $draft['description'],
                'hashtags' => $draft['hashtags'],
                'scheduled_for' => now(),
                'status' => ScheduledPost::STATUS_PUBLISHING,
                'created_by' => Auth::id(),
            ]);

            $post->log('info', sprintf('Envio imediato solicitado em %s.', Cast::str($account->name)));
            dispatch(new PublishScheduledPostJob($post->id));
        }

        Flux::toast(count($uuids).' corte(s) enviado(s) para publicacao no YouTube.');
        $this->reset('selectedCuts');
    }

    public function render(PostDraftBuilder $draftBuilder): View
    {
        $this->video->refresh()->load(['cuts.files', 'files', 'transcript']);

        $hlsMaster = $this->video->fileOfType('hls_master');
        $legendado = $this->video->fileOfType('legendado');
        $original = $this->video->fileOfType('original');
        $playable = $legendado ?? $original;

        $status = $this->video->status;
        $statusKey = $status instanceof Status ? $status->key : null;

        $ingestJobId = ($statusKey === 'downloading' || $statusKey === 'queued')
            && $this->video->current_job_id !== null
            ? $this->video->current_job_id
            : null;

        $activeJobId = $this->renderJobId ?? $ingestJobId;

        foreach ($this->video->cuts as $cut) {
            $this->cutEdits[$cut->uuid] ??= [
                'start' => (float) $cut->start_seconds,
                'end' => (float) $cut->end_seconds,
            ];
        }

        $timedWords = [];
        $payload = $this->video->payloads()
            ->whereIn('type', ['transcript_validated', 'transcript_raw'])
            // CASE (em vez de FIELD()) para funcionar em MySQL e SQLite: prioriza o validado.
            ->orderByRaw("CASE type WHEN 'transcript_validated' THEN 0 ELSE 1 END")
            ->first();

        if ($payload instanceof VideoPayload) {
            $data = $this->payloadData($payload);
            $segments = $data['segments'] ?? null;

            if (is_array($segments)) {
                foreach ($segments as $seg) {
                    if (! is_array($seg)) {
                        continue;
                    }

                    foreach (Cast::arr($seg['words'] ?? []) as $w) {
                        if (! is_array($w)) {
                            continue;
                        }

                        $timedWords[] = [
                            'text' => Cast::str($w['text'] ?? ''),
                            'start' => Cast::float($w['start'] ?? 0),
                            'end' => Cast::float($w['end'] ?? 0),
                        ];
                    }
                }
            }
        }

        $youtubeAccounts = SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($this->quickYoutubeAccount === '' && $youtubeAccounts->count() === 1) {
            $first = $youtubeAccounts->first();
            if ($first instanceof SocialAccount) {
                $this->quickYoutubeAccount = $first->uuid;
            }
        }

        $quickDrafts = [];
        foreach ($this->video->cuts as $cut) {
            $quickDrafts[$cut->uuid] = $draftBuilder->forCut($this->video, $cut);
        }

        return view('livewire.videos.editor', [
            'cuts' => $this->video->cuts,
            'hlsUrl' => $this->resolveHlsUrl($hlsMaster),
            'playerUrl' => $this->resolvePlayerUrl($playable),
            'transcript' => $this->video->transcript,
            'timedWords' => $timedWords,
            'statusKey' => $statusKey,
            'activeJobId' => $activeJobId,
            'wsUrl' => config('video-processor.ws_url'),
            'youtubeAccounts' => $youtubeAccounts,
            'quickDrafts' => $quickDrafts,
        ]);
    }

    private function resolveQuickYoutubeAccount(): ?SocialAccount
    {
        $query = SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('is_active', true);

        if ($this->quickYoutubeAccount !== '') {
            $account = (clone $query)->where('uuid', $this->quickYoutubeAccount)->first();

            return $account instanceof SocialAccount ? $account : null;
        }

        $accounts = $query->orderBy('name')->get();
        if ($accounts->count() !== 1) {
            return null;
        }

        $account = $accounts->first();

        return $account instanceof SocialAccount ? $account : null;
    }

    private function resolveVideoDuration(): float
    {
        $duration = Cast::float($this->video->duration_seconds ?? 0);
        if ($duration > 0) {
            return $duration;
        }

        $transcriptDuration = $this->video->transcript?->duration_seconds;

        return is_numeric($transcriptDuration) ? Cast::float($transcriptDuration) : 0.0;
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

    /**
     * @param  list<array<string, mixed>>  $words
     * @return list<array{text: string, start: float, end: float}>
     */
    private function normalizeTimedWords(array $words): array
    {
        $normalized = [];

        foreach ($words as $word) {
            $text = mb_trim(Cast::str($word['text'] ?? ''));
            $start = $word['start'] ?? null;
            $end = $word['end'] ?? null;
            if ($text === '') {
                continue;
            }

            if (! is_numeric($start)) {
                continue;
            }

            if (! is_numeric($end)) {
                continue;
            }

            $startFloat = (float) $start;
            $endFloat = (float) $end;
            if ($startFloat < 0) {
                continue;
            }

            if ($endFloat <= $startFloat) {
                continue;
            }

            $normalized[] = [
                'text' => $text,
                'start' => $startFloat,
                'end' => $endFloat,
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $cleaned = [];
        $previousEnd = 0.0;

        foreach ($normalized as $word) {
            $start = max($word['start'], $previousEnd);
            $end = $word['end'];

            if ($end <= $start) {
                continue;
            }

            $cleaned[] = [
                'text' => $word['text'],
                'start' => $start,
                'end' => $end,
            ];
            $previousEnd = $end;
        }

        return $cleaned;
    }
}
