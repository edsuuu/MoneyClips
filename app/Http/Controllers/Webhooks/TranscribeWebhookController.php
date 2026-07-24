<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\TranscriptionStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\TranscribeWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Models\File;
use App\Models\Video;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class TranscribeWebhookController extends Controller
{
    /**
     * @throws JsonException
     */
    public function __invoke(TranscribeWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $video = Video::query()->where('uuid', $request->uuid())->first();

        if (! $video instanceof Video) {
            return new StatusResource('unknown-job', 404);
        }

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

        $video->files()->updateOrCreate(['type' => File::TRANSCRIPT], [
            'path' => $video->transcriptPath(),
            'mime_type' => 'application/json',
        ]);

        return new StatusResource('ready');
    }
}
