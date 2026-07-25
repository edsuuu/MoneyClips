<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\CutWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Jobs\StartCutTranscribeJob;
use App\Models\File;
use App\Models\VideoCut;
use App\Services\API\Discord\DiscordNotifierService;

final class CutWebhookController extends Controller
{
    public function __invoke(CutWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $cut = VideoCut::query()->with('video')->where('uuid', $request->cutUuid())->first();

        if (! $cut instanceof VideoCut) {
            return new StatusResource('unknown-job', 404);
        }

        if ($request->failed()) {
            $claimed = VideoCut::query()
                ->whereKey($cut->id)
                ->where('status', VideoCutStatusEnum::Generating->value)
                ->update([
                    'status' => VideoCutStatusEnum::Failed,
                    'error' => $request->error() ?? 'A geração do corte falhou sem detalhe.',
                ]);

            if ($claimed === 1) {
                $discord->error(
                    '❌ Geração de corte falhou',
                    sprintf('Corte #%d (%s)%s%s', $cut->id, $cut->uuid, PHP_EOL, $request->error() ?? ''),
                );
            }

            return new StatusResource('failure-recorded');
        }

        $claimed = VideoCut::query()
            ->whereKey($cut->id)
            ->where('status', VideoCutStatusEnum::Generating->value)
            ->update(['status' => VideoCutStatusEnum::Ready, 'error' => null]);

        if ($claimed !== 1) {
            return new StatusResource('already-finished');
        }

        $cut->video->files()->updateOrCreate(
            ['type' => File::CLIP, 'video_cut_id' => $cut->id],
            ['path' => $cut->clipPath(), 'mime_type' => 'video/mp4'],
        );

        if ($request->hasAudio()) {
            $cut->video->files()->updateOrCreate(
                ['type' => File::AUDIO, 'video_cut_id' => $cut->id],
                ['path' => $cut->clipAudioPath(), 'mime_type' => 'audio/wav'],
            );

            $this->startTranscription($cut);
        }

        return new StatusResource('ready');
    }

    private function startTranscription(VideoCut $cut): void
    {
        $claimed = VideoCut::query()
            ->whereKey($cut->id)
            ->whereNull('transcription_status')
            ->update(['transcription_status' => TranscriptionStatusEnum::Processing]);

        if ($claimed === 1) {
            dispatch(new StartCutTranscribeJob($cut->id));
        }
    }
}
