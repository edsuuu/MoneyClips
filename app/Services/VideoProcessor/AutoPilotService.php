<?php

declare(strict_types=1);

namespace App\Services\VideoProcessor;

use App\Models\Status;
use App\Models\Transcript;
use App\Models\Video;
use App\Services\Status\StatusService;
use App\Support\Cast;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra o "piloto automático" de um vídeo: confirma a transcrição,
 * gera os cortes (cobrindo o vídeo inteiro em vídeos curtos, ou pedindo os
 * melhores momentos à IA em vídeos longos) e dispara a renderização — tudo
 * sem nenhuma etapa manual.
 *
 * É chamado pelo RunAutoPilotJob assim que a ingestão (download+transcrição)
 * termina e o callback marca o vídeo como pronto para revisão.
 */
final readonly class AutoPilotService
{
    public function __construct(
        private VideoProcessorService $videoProcessor,
        private StatusService $status,
    ) {}

    public function run(Video $video): void
    {
        if (! $video->is_auto) {
            return;
        }

        // Idempotência: se já existem cortes, o piloto automático já rodou.
        if ($video->cuts()->exists()) {
            return;
        }

        $transcript = $video->transcript;
        if (! $transcript instanceof Transcript) {
            Log::warning('AutoPilot: transcrição ausente, abortando.', ['video' => $video->uuid]);

            return;
        }

        $this->confirmTranscript($video, $transcript);

        $duration = $this->resolveDuration($video, $transcript);
        $count = $video->auto_clip_count !== null && $video->auto_clip_count > 0 ? Cast::int($video->auto_clip_count) : null;

        if ($this->resolveStrategy($video, $duration) === 'sequential') {
            $this->createSequentialCuts($video, $duration, $count);
        } else {
            $this->createAiCuts($video, $count);
        }

        if (! $video->cuts()->exists()) {
            $this->status->transition($video, 'waiting_cuts', 'Piloto automático não gerou cortes; aguardando ação manual');

            return;
        }

        $cuts = $video->cuts()->get();
        $this->videoProcessor->startRenderCuts($video, $cuts);
    }

    private function confirmTranscript(Video $video, Transcript $transcript): void
    {
        if (! $transcript->is_confirmed_by_user) {
            $transcript->update([
                'edited_text' => $transcript->activeText(),
                'is_confirmed_by_user' => true,
                'confirmed_at' => now(),
            ]);
        }

        $this->status->transition($video, 'transcript_confirmed', 'Transcrição confirmada automaticamente (piloto automático)');
        $this->status->transition($video, 'waiting_cuts', 'Cortes serão gerados automaticamente');
    }

    /**
     * Decide a estratégia efetiva de cortes a partir do modo escolhido e da duração.
     * Modo 'sequential' sem duração conhecida cai para IA.
     */
    private function resolveStrategy(Video $video, float $duration): string
    {
        $mode = in_array($video->auto_mode, ['auto', 'sequential', 'ai'], true) ? $video->auto_mode : 'auto';

        if ($mode === 'ai') {
            return 'ai';
        }

        if ($mode === 'sequential') {
            return $duration > 0 ? 'sequential' : 'ai';
        }

        // auto: decide pela duração.
        $threshold = Cast::int(config('video-processor.auto.full_coverage_max_seconds', 900));

        return $duration > 0 && $duration <= $threshold ? 'sequential' : 'ai';
    }

    /**
     * Vídeos curtos / modo "1 minuto": fatia o vídeo em clipes sequenciais de ~clip_seconds.
     * Ex.: vídeo de 10min com clip_seconds=60 vira 10 clipes de 1min. Se $limit for
     * informado, gera no máximo essa quantidade de clipes.
     */
    private function createSequentialCuts(Video $video, float $duration, ?int $limit = null): void
    {
        $clip = max(1, Cast::int(config('video-processor.auto.clip_seconds', 60)));
        $minTail = max(0, Cast::int(config('video-processor.auto.min_tail_seconds', 20)));

        $segments = [];
        $start = 0.0;
        $index = 0;

        while ($start < $duration - 0.5) {
            $index++;
            $end = min($start + $clip, $duration);
            $remaining = $duration - $end;

            // Absorve uma sobra final muito curta no clipe atual em vez de criar um clipe minúsculo.
            if ($remaining > 0 && $remaining < $minTail) {
                $end = $duration;
            }

            $segments[] = ['index' => $index, 'start' => $start, 'end' => $end];
            $start = $end;
        }

        if ($limit !== null && $limit > 0) {
            $segments = array_slice($segments, 0, $limit);
        }

        foreach ($segments as $seg) {
            $this->persistCut($video, [
                'index' => $seg['index'],
                'name' => 'PT'.$seg['index'],
                'type' => 'pt'.$seg['index'],
                'source' => 'auto',
                'start_seconds' => $seg['start'],
                'end_seconds' => $seg['end'],
                'duration_seconds' => $seg['end'] - $seg['start'],
            ]);
        }
    }

    /**
     * Vídeos longos / modo "IA": a IA escolhe os melhores momentos. Se $count for
     * informado, pede exatamente essa quantidade de cortes.
     */
    private function createAiCuts(Video $video, ?int $count = null): void
    {
        if ($count !== null && $count > 0) {
            $constraints = ['min_cuts' => $count, 'max_cuts' => $count];
        } else {
            $constraints = [
                'min_cuts' => Cast::int(config('video-processor.auto.ai_min_cuts', 8)),
                'max_cuts' => Cast::int(config('video-processor.auto.ai_max_cuts', 20)),
            ];
        }

        $cuts = $this->videoProcessor->recommendCuts($video, null, $constraints);

        foreach ($cuts as $item) {
            if (! isset($item['start_seconds'], $item['end_seconds'])) {
                continue;
            }

            $maxIndexVal = $video->cuts()->max('index');
            $maxIndex = is_numeric($maxIndexVal) ? (int) $maxIndexVal : 0;
            $index = isset($item['index']) && is_numeric($item['index']) ? (int) $item['index'] : $maxIndex + 1;

            $this->persistCut($video, [
                'index' => $index,
                'name' => is_string($item['name'] ?? null) ? $item['name'] : 'PT'.$index,
                'type' => is_string($item['type'] ?? null) ? $item['type'] : 'pt'.$index,
                'source' => 'ai',
                'start_seconds' => Cast::float($item['start_seconds']),
                'end_seconds' => Cast::float($item['end_seconds']),
                'duration_seconds' => isset($item['duration_seconds']) ? Cast::float($item['duration_seconds']) : Cast::float($item['end_seconds']) - Cast::float($item['start_seconds']),
                'score' => isset($item['score']) && is_numeric($item['score']) ? Cast::float($item['score']) : null,
                'reason' => is_string($item['reason'] ?? null) ? $item['reason'] : null,
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function persistCut(Video $video, array $attributes): void
    {
        $index = Cast::int($attributes['index']);

        $video->cuts()->updateOrCreate(
            ['video_id' => $video->id, 'index' => $index],
            array_merge($attributes, ['status_id' => Status::idFor('pending')]),
        );
    }

    private function resolveDuration(Video $video, Transcript $transcript): float
    {
        $duration = Cast::float($video->duration_seconds ?? 0);
        if ($duration > 0) {
            return $duration;
        }

        return Cast::float($transcript->duration_seconds ?? 0);
    }
}
