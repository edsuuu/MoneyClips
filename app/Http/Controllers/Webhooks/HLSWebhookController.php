<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\HLSWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Jobs\StartTranscribeJob;
use App\Models\File;
use App\Models\Video;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Support\Facades\Storage;

final class HLSWebhookController extends Controller
{
    public function __invoke(HLSWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $video = Video::query()->where('uuid', $request->videoUuid())->first();

        if (! $video instanceof Video) {
            return new StatusResource('unknown-job', 404);
        }

        if ($video->status->isTerminal()) {
            return new StatusResource('already-finished');
        }

        if ($request->status() === 'progress') {
            Video::query()
                ->whereKey($video->id)
                ->where('progress', '<', $request->progress())
                ->update(['progress' => $request->progress()]);

            return new StatusResource('progress-recorded');
        }

        if ($request->status() !== 'done') {
            $this->finishWithFailure($video, $request->status(), $request->error(), $discord);

            return new StatusResource('failure-recorded');
        }

        $this->recordArtifacts($video, $request);

        $video->fill([
            'status' => VideoStatusEnum::Ready,
            'progress' => 100,
            'duration_seconds' => $request->durationSeconds(),
            'width' => $request->width(),
            'height' => $request->height(),
            'hash' => $request->hash(),
            'error' => null,
            'ready_at' => now(),
        ])->save();

        $this->startTranscription($video, $request->hasAudio());

        return new StatusResource('ready');
    }

    private function startTranscription(Video $video, bool $hasAudio): void
    {
        if (! $hasAudio) {
            return;
        }

        $claimed = Video::query()
            ->whereKey($video->id)
            ->whereNull('transcription_status')
            ->update(['transcription_status' => TranscriptionStatusEnum::Processing]);

        if ($claimed === 1) {
            dispatch(new StartTranscribeJob($video->id));
        }
    }

    private function recordArtifacts(Video $video, HLSWebhookRequest $request): void
    {
        $video->files()->updateOrCreate(['type' => File::HLS], [
            'path' => $video->masterPlaylistPath(),
            'meta' => [
                'renditions' => $request->renditions(),
                'duration' => $request->durationSeconds(),
                'width' => $request->width(),
                'height' => $request->height(),
            ],
        ]);

        if ($request->hasPoster()) {
            $video->files()->updateOrCreate(['type' => File::POSTER], ['path' => $video->posterPath()]);
        }

        if ($request->hasAudio()) {
            $video->files()->updateOrCreate(['type' => File::AUDIO, 'video_cut_id' => null], [
                'path' => $video->audioPath(),
                'mime_type' => 'audio/wav',
            ]);
        }

        $storyboard = $request->storyboard();

        if ($storyboard !== null) {
            $video->files()->updateOrCreate(['type' => File::STORYBOARD], [
                'path' => $video->storyboardPath(),
                'meta' => $storyboard,
            ]);
        }
    }

    private function finishWithFailure(Video $video, string $status, ?string $error, DiscordNotifierService $discord): void
    {
        $rejected = $status === 'rejected';

        $video->fill([
            'status' => $rejected ? VideoStatusEnum::Rejected : VideoStatusEnum::Failed,
            'error' => $error ?? 'O empacotamento falhou sem detalhe.',
        ])->save();

        if ($rejected) {
            Storage::disk('s3')->deleteDirectory($video->prefix());
        }

        $discord->error(
            $rejected ? '⚠️ Upload recusado no empacotamento' : '❌ Empacotamento HLS falhou',
            sprintf('Vídeo #%d (%s)%s%s', $video->id, $video->uuid, PHP_EOL, $video->error ?? ''),
        );
    }
}
