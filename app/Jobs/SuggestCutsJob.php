<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptionStatusEnum;
use App\Models\Video;
use App\Models\VideoCut;
use App\Services\API\Discord\DiscordNotifierService;
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

            return;
        }

        $duration = (float) ($video->duration_seconds ?? 0);

        // Sem duração não dá pra clampar o que a IA devolver, e aceitar
        // timestamp fora do vídeo geraria corte que o ffmpeg não corta.
        throw_if($duration <= 0.0, RuntimeException::class, sprintf('Vídeo #%d não tem duração conhecida.', $video->id));

        $suggestions = $validator->validate(
            $provider->suggest($video, $this->transcriptFor($video), $this->prompt),
            $duration,
        );

        $created = DB::transaction(function () use ($video, $suggestions): int {
            $created = 0;

            foreach ($suggestions as $suggestion) {
                $start = (int) floor($suggestion->start);
                $end = (int) ceil($suggestion->end);
                if ($end - $start < 1) {
                    continue;
                }

                if ($end - $start > VideoCut::MAX_DURATION_SECONDS) {
                    continue;
                }

                $exists = $video->cuts()
                    ->where('start_seconds', $start)
                    ->where('end_seconds', $end)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $video->cuts()->create([
                    'start_seconds' => $start,
                    'end_seconds' => $end,
                    'is_ai_generated' => true,
                ]);

                $created++;
            }

            return $created;
        });

        Log::channel('daily')->info('[INFO][CutSuggestion] Cortes sugeridos gravados.', [
            'video_id' => $video->id,
            'suggested' => count($suggestions),
            'created' => $created,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao sugerir cortes.';

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
