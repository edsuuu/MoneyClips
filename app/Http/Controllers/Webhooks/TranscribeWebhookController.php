<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\TranscriptionStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\TranscribeWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Models\File;
use App\Models\Video;
use App\Models\VideoCut;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * O transcriber correlaciona por uuid e atende dois donos: o vídeo longo
 * (áudio principal) e o corte (áudio do clip). O uuid decide o branch.
 */
final class TranscribeWebhookController extends Controller
{
    /**
     * @throws JsonException
     */
    public function __invoke(TranscribeWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $video = Video::query()->where('uuid', $request->uuid())->first();

        if ($video instanceof Video) {
            return $this->handleVideo($video, $request, $discord);
        }

        $cut = VideoCut::query()->with('video')->where('uuid', $request->uuid())->first();

        if ($cut instanceof VideoCut) {
            return $this->handleCut($cut, $request, $discord);
        }

        return new StatusResource('unknown-job', 404);
    }

    /**
     * @throws JsonException
     */
    private function handleVideo(Video $video, TranscribeWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        if ($video->transcription_status?->isTerminal()) {
            return new StatusResource('already-finished');
        }

        $claimed = Video::query()
            ->whereKey($video->id)
            ->where('transcription_status', TranscriptionStatusEnum::Processing->value)
            ->update(['transcription_status' => $request->failed() ? TranscriptionStatusEnum::Failed : TranscriptionStatusEnum::Ready]);

        if ($claimed !== 1) {
            return new StatusResource('already-finished');
        }

        if ($request->failed()) {
            $discord->error(
                '❌ Transcrição falhou',
                sprintf('Vídeo #%d (%s)%s%s', $video->id, $video->uuid, PHP_EOL, $request->error() ?? ''),
            );

            return new StatusResource('failure-recorded');
        }

        Storage::disk('s3')->put(
            $video->transcriptPath(),
            json_encode($request->transcript(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        $video->files()->updateOrCreate(['type' => File::TRANSCRIPT, 'video_cut_id' => null], [
            'path' => $video->transcriptPath(),
            'mime_type' => 'application/json',
        ]);

        return new StatusResource('ready');
    }

    /**
     * @throws JsonException
     */
    private function handleCut(VideoCut $cut, TranscribeWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        if ($cut->transcription_status?->isTerminal()) {
            return new StatusResource('already-finished');
        }

        $claimed = VideoCut::query()
            ->whereKey($cut->id)
            ->where('transcription_status', TranscriptionStatusEnum::Processing->value)
            ->update(['transcription_status' => $request->failed() ? TranscriptionStatusEnum::Failed : TranscriptionStatusEnum::Ready]);

        if ($claimed !== 1) {
            return new StatusResource('already-finished');
        }

        if ($request->failed()) {
            $discord->error(
                '❌ Transcrição do corte falhou',
                sprintf('Corte #%d (%s)%s%s', $cut->id, $cut->uuid, PHP_EOL, $request->error() ?? ''),
            );

            return new StatusResource('failure-recorded');
        }

        Storage::disk('s3')->put(
            $cut->transcriptPath(),
            json_encode($request->transcript(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        $cut->video->files()->updateOrCreate(['type' => File::TRANSCRIPT, 'video_cut_id' => $cut->id], [
            'path' => $cut->transcriptPath(),
            'mime_type' => 'application/json',
        ]);

        return new StatusResource('ready');
    }
}
