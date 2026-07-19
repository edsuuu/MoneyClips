<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Video;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Desfecho do empacotamento. Um job de horas termina fora da sessão do
 * operador, então este é o único ponto que fecha o ciclo do vídeo.
 */
final class HLSWebhookController extends Controller
{
    public function __invoke(Request $request, DiscordNotifierService $discord): JsonResponse
    {
        /** @var array{uuid: string, status: string, progress?: int|null, error?: string|null, duration_seconds?: int|null, width?: int|null, height?: int|null, hash?: string|null, renditions?: list<string>|null, poster?: bool|null} $data */
        $data = $request->validate([
            'uuid' => ['required', 'string'],
            'status' => ['required', 'in:done,failed,rejected,progress'],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'error' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'hash' => ['nullable', 'string', 'size:32'],
            'renditions' => ['nullable', 'array'],
            'renditions.*' => ['string', 'max:16'],
            'poster' => ['nullable', 'boolean'],
        ]);

        $video = Video::query()->where('hls_remote_id', $data['uuid'])->first();

        if (! $video instanceof Video) {
            return response()->json(['status' => 'unknown-job'], 404);
        }

        // Idempotência: retry do webhook depois do desfecho não refaz nada.
        if ($video->status->isTerminal()) {
            return response()->json(['status' => 'already-finished']);
        }

        if ($data['status'] === 'progress') {
            // O progresso só avança: webhooks fora de ordem não podem fazer a
            // barra regredir.
            Video::query()
                ->whereKey($video->id)
                ->where('progress', '<', $data['progress'] ?? 0)
                ->update(['progress' => $data['progress'] ?? 0]);

            return response()->json(['status' => 'progress-recorded']);
        }

        if ($data['status'] !== 'done') {
            $this->finishWithFailure($video, $data['status'], $data['error'] ?? null, $discord);

            return response()->json(['status' => 'failure-recorded']);
        }

        $video->fill([
            'status' => VideoStatusEnum::Ready,
            'progress' => 100,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'hash' => $data['hash'] ?? null,
            'renditions' => $data['renditions'] ?? [],
            'hls_path' => $video->hlsPrefix(),
            'poster_path' => ($data['poster'] ?? false) ? $video->hlsPrefix().'/poster.jpg' : null,
            'error' => null,
            'ready_at' => now(),
        ])->save();

        return response()->json(['status' => 'ready']);
    }

    private function finishWithFailure(Video $video, string $status, ?string $error, DiscordNotifierService $discord): void
    {
        $rejected = $status === 'rejected';

        $video->fill([
            'status' => $rejected ? VideoStatusEnum::Rejected : VideoStatusEnum::Failed,
            'error' => $error ?? 'O empacotamento falhou sem detalhe.',
        ])->save();

        // Arquivo recusado não vira vídeo nunca: o binário só ocuparia espaço.
        if ($rejected) {
            Storage::disk('s3')->delete($video->path());
        }

        $discord->error(
            $rejected ? '⚠️ Upload recusado no empacotamento' : '❌ Empacotamento HLS falhou',
            sprintf('Vídeo #%d (%s)%s%s', $video->id, $video->uuid, PHP_EOL, $video->error ?? ''),
        );
    }
}
