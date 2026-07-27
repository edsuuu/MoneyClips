<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\VideoStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\DownloadVideoWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Jobs\StartHLSPackagingJob;
use App\Models\File;
use App\Models\Video;
use App\Services\API\Discord\DiscordNotifierService;

final class DownloadVideoWebhookController extends Controller
{
    public function __invoke(DownloadVideoWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $video = Video::query()->where('uuid', $request->videoUuid())->first();

        if (! $video instanceof Video) {
            return new StatusResource('unknown-job', 404);
        }

        if ($request->status() !== 'completed') {
            return $this->finishWithFailure($video, $request->error(), $discord);
        }

        $claimed = Video::query()
            ->whereKey($video->id)
            ->where('status', VideoStatusEnum::Downloading)
            ->update([
                'status' => VideoStatusEnum::Uploaded,
                'duration_seconds' => $request->durationSeconds(),
                'width' => $request->width(),
                'height' => $request->height(),
            ]);

        if ($claimed !== 1) {
            return new StatusResource('already-finished');
        }

        $video->files()->updateOrCreate(['type' => File::ORIGINAL, 'video_cut_id' => null], [
            'path' => $video->originalPath(),
            'size' => $request->sizeBytes(),
            'mime_type' => 'video/mp4',
        ]);

        dispatch(new StartHLSPackagingJob($video->id));

        return new StatusResource('download-recorded');
    }

    private function finishWithFailure(Video $video, ?string $error, DiscordNotifierService $discord): StatusResource
    {
        $claimed = Video::query()
            ->whereKey($video->id)
            ->where('status', VideoStatusEnum::Downloading)
            ->update([
                'status' => VideoStatusEnum::Failed,
                'error' => $error ?? 'O download do YouTube falhou sem detalhe.',
            ]);

        if ($claimed !== 1) {
            return new StatusResource('already-finished');
        }

        $discord->error(
            '❌ Download do YouTube falhou',
            sprintf('Vídeo #%d (%s)%s%s', $video->id, $video->uuid, PHP_EOL, $error ?? ''),
        );

        return new StatusResource('failure-recorded');
    }
}
