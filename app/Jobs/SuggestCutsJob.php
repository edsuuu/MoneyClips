<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptionStatusEnum;
use App\Models\Video;
use App\Models\VideoCut;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\CutSuggestion\CutSuggestionData;
use App\Services\CutSuggestion\CutSuggestionInterface;
use App\Services\CutSuggestion\CutSuggestionValidatorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Pede trechos à IA e materializa cada um como corte em rascunho. O operador
 * revisa e gera os clips normalmente — a sugestão não dispara render nenhum.
 */
final class SuggestCutsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $videoId, public string $prompt)
    {
        $this->onQueue('processing');
    }

    /**
     * @throws Throwable
     */
    public function handle(CutSuggestionInterface $provider, CutSuggestionValidatorService $validator): void
    {
        $video = Video::query()->find($this->videoId);

        if (! $video instanceof Video) {
            Log::channel('daily')->warning('[WARN][CutSuggestion] Vídeo inexistente ao sugerir cortes.', ['id' => $this->videoId]);

            return;
        }

        if ($video->transcription_status !== TranscriptionStatusEnum::Ready) {
            Log::channel('daily')->info('[INFO][CutSuggestion] Transcrição não está pronta — ignorando.', [
                'video_id' => $video->id,
                'transcription_status' => $video->transcription_status?->value,
            ]);

            $video->update([
                'cut_suggestion_status' => TranscriptionStatusEnum::Failed,
                'cut_suggestion_error' => 'A transcrição precisa estar pronta antes de buscar momentos.',
            ]);

            return;
        }

        $duration = (float) ($video->duration_seconds ?? 0);

        // Sem duração não dá pra clampar o que a IA devolver, e aceitar
        // timestamp fora do vídeo geraria corte que o ffmpeg não corta.
        throw_if($duration <= 0.0, RuntimeException::class, sprintf('Vídeo #%d não tem duração conhecida.', $video->id));

        $transcript = $this->transcriptFor($video);
        $raw = $provider->suggest($video, $transcript, $this->prompt);
        $aligned = array_map(
            fn (CutSuggestionData $suggestion): CutSuggestionData => $suggestion->withBounds(...$this->alignToWords($transcript, $suggestion->start, $suggestion->end)),
            $raw,
        );
        $suggestions = $validator->validate($aligned, $duration);

        $created = DB::transaction(function () use ($video, $raw, $suggestions): int {
            $created = 0;

            foreach ($suggestions as $suggestion) {
                $start = $suggestion->start;
                $end = $suggestion->end;

                if ($end - $start > VideoCut::MAX_DURATION_SECONDS) {
                    continue;
                }

                $overlaps = $video->cuts()
                    ->where('start_seconds', '<', $end)
                    ->where('end_seconds', '>', $start)
                    ->exists();

                if ($overlaps) {
                    continue;
                }

                $video->cuts()->create([
                    'start_seconds' => $start,
                    'end_seconds' => $end,
                    'is_ai_generated' => true,
                    'score' => $suggestion->score,
                    'reason' => $suggestion->reason === '' ? null : $suggestion->reason,
                    'title' => $suggestion->title === '' ? null : $suggestion->title,
                    'hashtags' => $suggestion->hashtags === [] ? null : $suggestion->hashtags,
                ]);

                $created++;
            }

            if ($created === 0) {
                $error = sprintf('Nenhum momento novo: os %d trecho(s) que passaram nas regras já existem como corte.', count($suggestions));

                if ($suggestions === []) {
                    $error = sprintf(
                        'Nenhum momento novo: dos %d trecho(s) que a IA sugeriu, nenhum fechou entre %d e %ds sem sobreposição.',
                        count($raw),
                        (int) config('services.cut_suggestion.min_duration'),
                        (int) config('services.cut_suggestion.max_duration'),
                    );
                }

                $video->update([
                    'cut_suggestion_status' => TranscriptionStatusEnum::Failed,
                    'cut_suggestion_error' => $error,
                ]);

                return 0;
            }

            $video->update([
                'cut_suggestion_status' => TranscriptionStatusEnum::Ready,
                'cut_suggestion_error' => null,
            ]);

            return $created;
        });

        Log::channel('daily')->info('[INFO][CutSuggestion] Cortes sugeridos gravados.', [
            'video_id' => $video->id,
            'raw' => count($raw),
            'suggested' => count($suggestions),
            'created' => $created,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao sugerir cortes.';

        Video::query()
            ->whereKey($this->videoId)
            ->where('cut_suggestion_status', TranscriptionStatusEnum::Processing->value)
            ->update([
                'cut_suggestion_status' => TranscriptionStatusEnum::Failed,
                'cut_suggestion_error' => $error,
            ]);

        Log::channel('daily')->error('[ERRO][CutSuggestion] Sugestão de cortes falhou.', [
            'video_id' => $this->videoId,
            'exception' => $exception,
        ]);

        resolve(DiscordNotifierService::class)->error(
            '❌ Sugestão de cortes falhou',
            sprintf('Vídeo #%d%s%s', $this->videoId, PHP_EOL, $error),
        );
    }

    /**
     * Estende a borda que cai no meio de uma palavra até a borda dela; a
     * risada depois da última palavra, que a IA incluiu de propósito, fica.
     * Sem palavras com tempo, mantém o que a IA devolveu.
     *
     * @param  array<mixed>  $transcript
     * @return array{float, float}
     */
    private function alignToWords(array $transcript, float $start, float $end): array
    {
        $first = null;
        $last = null;

        foreach ((array) ($transcript['segments'] ?? []) as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            foreach ((array) ($segment['words'] ?? []) as $word) {
                if (! is_array($word) || ! isset($word['start'], $word['end'])) {
                    continue;
                }

                if ($first === null && (float) $word['end'] > $start) {
                    $first = (float) $word['start'];
                }

                if ((float) $word['start'] < $end) {
                    $last = (float) $word['end'];
                }
            }
        }

        if ($first === null || $last === null || $last <= $first) {
            return [$start, $end];
        }

        return [min($start, $first), max($end, $last)];
    }

    /**
     * @return array<mixed>
     *
     * @throws JsonException
     */
    private function transcriptFor(Video $video): array
    {
        $raw = Storage::disk('s3')->get($video->transcriptPath());
        $decoded = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;

        throw_unless(is_array($decoded), RuntimeException::class, sprintf('Transcrição ilegível do vídeo #%d.', $video->id));

        return $decoded;
    }
}
