<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Models\ReframeEdit;
use App\Models\VideoCut;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Reframe\ReframeRenderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Dispara o render do corte editado e encerra: o ffmpeg roda no microserviço e
 * quem fecha o ciclo é o webhook /api/webhook/reframe.
 */
final class StartReframeRenderJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $editId)
    {
        $this->onQueue('processing');
    }

    /**
     * @throws Throwable
     */
    public function handle(ReframeRenderService $service): void
    {
        $edit = ReframeEdit::query()->with('videoCut.video')->find($this->editId);

        if (! $edit instanceof ReframeEdit) {
            Log::warning('[Reframe] Edição inexistente ao iniciar render.', ['id' => $this->editId]);

            return;
        }

        if ($edit->render_status !== VideoCutStatusEnum::Generating) {
            Log::info('[Reframe] Edição fora do estado "generating" — ignorando.', [
                'id' => $edit->id,
                'render_status' => $edit->render_status?->value,
            ]);

            return;
        }

        throw_unless(
            Storage::disk('s3')->exists($edit->source_path),
            RuntimeException::class,
            sprintf('Clip fonte da edição não encontrado no s3: "%s".', $edit->source_path),
        );

        $service->startRender($edit, $this->transcriptFor($edit));

        Log::info('[Reframe] Render do corte editado iniciado.', ['edit_id' => $edit->id, 'uuid' => $edit->uuid]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao iniciar o render da edição.';

        // Guard simétrico ao claim do webhook: se o "done" chegou no meio dos
        // retries, não sobrescreve o ready (short já está no estoque).
        $claimed = ReframeEdit::query()
            ->whereKey($this->editId)
            ->where('render_status', VideoCutStatusEnum::Generating->value)
            ->update([
                'render_status' => VideoCutStatusEnum::Failed,
                'render_error' => $error,
            ]);

        if ($claimed !== 1) {
            return;
        }

        resolve(DiscordNotifierService::class)->error(
            '❌ Render de corte editado falhou ao iniciar',
            sprintf('Edição #%d%s%s', $this->editId, PHP_EOL, $error),
        );
    }

    /** @return array<mixed>|null
     * @throws JsonException
     */
    private function transcriptFor(ReframeEdit $edit): ?array
    {
        $cut = $edit->videoCut;

        if (! $cut instanceof VideoCut || ! (bool) ($edit->settings['captions'] ?? false)) {
            return null;
        }

        if ($cut->transcription_status !== TranscriptionStatusEnum::Ready) {
            return null;
        }

        // Legendas foram pedidas e a transcrição está pronta: falha de leitura
        // aqui deve derrubar o job (retry → failed + Discord), não render mudo.
        $raw = Storage::disk('s3')->get($cut->transcriptPath());

        $decoded = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
