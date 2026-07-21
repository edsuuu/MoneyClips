<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\HLSWebhookRequest;
use App\Models\Video;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

final class HLSWebhookController extends Controller
{
    public function __invoke(HLSWebhookRequest $request, DiscordNotifierService $discord): JsonResponse
    {
        $video = Video::query()->where('hls_remote_id', $request->uuid())->first();

        if (! $video instanceof Video) {
            return response()->json(['status' => 'unknown-job'], 404);
        }

        if ($video->status->isTerminal()) {
            return response()->json(['status' => 'already-finished']);
        }

        if ($request->status() === 'progress') {
            Video::query()
                ->whereKey($video->id)
                ->where('progress', '<', $request->progress())
                ->update(['progress' => $request->progress()]);

            return response()->json(['status' => 'progress-recorded']);
        }

        if ($request->status() !== 'done') {
            $this->finishWithFailure($video, $request->status(), $request->error(), $discord);

            return response()->json(['status' => 'failure-recorded']);
        }

        $video->fill([
            'status' => VideoStatusEnum::Ready,
            'progress' => 100,
            'duration_seconds' => $request->durationSeconds(),
            'width' => $request->width(),
            'height' => $request->height(),
            'hash' => $request->hash(),
            'renditions' => $request->renditions(),
            'hls_path' => $video->hlsPrefix(),
            'poster_path' => $request->hasPoster() ? $video->hlsPrefix().'/poster.jpg' : null,
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

        if ($rejected) {
            Storage::disk('s3')->delete($video->path());
        }

        $discord->error(
            $rejected ? '⚠️ Upload recusado no empacotamento' : '❌ Empacotamento HLS falhou',
            sprintf('Vídeo #%d (%s)%s%s', $video->id, $video->uuid, PHP_EOL, $video->error ?? ''),
        );
    }
}
