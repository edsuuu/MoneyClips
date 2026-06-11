<?php

declare(strict_types=1);

namespace App\Services\VideoProcessor;

use App\Models\Cut;
use App\Models\File;
use App\Models\Status;
use App\Models\Video;
use App\Services\Status\StatusService;
use Illuminate\Support\Facades\DB;

/**
 * Processa o webhook que a API de processamento envia ao concluir cada etapa.
 */
final readonly class VideoProcessorCallbackService
{
    public function __construct(private StatusService $status) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): Video
    {
        $videoId = $payload['video_id'] ?? '';
        $videoIdStr = is_string($videoId) ? $videoId : '';
        $video = Video::query()->where('uuid', $videoIdStr)->firstOrFail();

        $files = $payload['files'] ?? null;
        /** @var list<array<string, mixed>> $filesList */
        $filesList = is_array($files) ? array_values($files) : [];

        $transcript = $payload['transcript'] ?? null;
        /** @var array<string, mixed>|null $transcriptArr */
        $transcriptArr = is_array($transcript) ? $transcript : null;

        $payloads = $payload['payloads'] ?? null;
        /** @var list<array<string, mixed>> $payloadsList */
        $payloadsList = is_array($payloads) ? array_values($payloads) : [];

        $statusKeyVal = $payload['status'] ?? '';
        $statusKey = is_string($statusKeyVal) ? $statusKeyVal : '';

        $messageVal = $payload['message'] ?? ($payload['event'] ?? '');
        $message = is_string($messageVal) ? $messageVal : '';

        $eventVal = $payload['event'] ?? null;
        $eventStr = is_string($eventVal) ? $eventVal : null;

        $isFailure = $this->isFailure($payload);

        // Salva dados do vídeo, transcrição, arquivos e payloads numa transação própria.
        // Separada da transição de status para garantir que os dados chegam ao banco
        // mesmo que o status_logs falhe (ex.: message muito longa em ambientes legados).
        $video = DB::transaction(function () use ($video, $filesList, $transcriptArr, $payloadsList, $payload, $isFailure): Video {
            $this->saveFiles($video, $filesList);
            $this->saveTranscript($video, $transcriptArr);
            $this->savePayloads($video, $payloadsList);
            $this->markRenderedCuts($filesList);

            $videoData = $payload['video'] ?? null;
            if (is_array($videoData)) {
                if ($video->title === null && isset($videoData['title']) && is_string($videoData['title'])) {
                    $video->title = $videoData['title'];
                }

                if (isset($videoData['external_video_id']) && is_string($videoData['external_video_id'])) {
                    $video->external_video_id = $videoData['external_video_id'];
                }

                if (isset($videoData['duration_seconds']) && (is_float($videoData['duration_seconds']) || is_numeric($videoData['duration_seconds']))) {
                    $video->duration_seconds = (float) $videoData['duration_seconds'];
                }
            }

            $video->progress = $isFailure ? $video->progress : 100;
            $video->save();

            return $video->refresh();
        });

        // Transições de status ficam fora da transação de dados para que um erro de
        // status_logs não descarte transcrição/arquivos já salvos.
        if ($statusKey !== '' && Status::idFor($statusKey) !== 0) {
            $this->status->transition($video, $statusKey, $message, ['event' => $eventStr]);
        }

        return $video;
    }

    /** @param  list<array<string, mixed>>  $files */
    private function saveFiles(Video $video, array $files): void
    {
        foreach ($files as $file) {
            $cutId = null;
            $cutUuid = $file['cut_id'] ?? null;
            if (is_string($cutUuid) && $cutUuid !== '') {
                $cutId = Cut::query()->where('uuid', $cutUuid)->value('id');
            }

            $type = $file['type'] ?? null;
            $path = $file['path'] ?? null;
            $typeStr = is_string($type) ? $type : '';
            $pathStr = is_string($path) ? $path : '';

            File::query()->updateOrCreate([
                'video_id' => $video->id,
                'type' => $typeStr,
                'path' => $pathStr,
            ], [
                'cut_id' => $cutId,
                'mime_type' => $file['mime_type'] ?? null,
                'extension' => $file['extension'] ?? null,
                'size_bytes' => $file['size_bytes'] ?? null,
                'checksum_sha256' => $file['checksum_sha256'] ?? null,
            ]);
        }
    }

    /** @param  array<string, mixed>|null  $transcript */
    private function saveTranscript(Video $video, ?array $transcript): void
    {
        if ($transcript === null || empty($transcript['raw_text']) && empty($transcript['validated_text'])) {
            return;
        }

        $video->transcript()->updateOrCreate(
            ['video_id' => $video->id],
            [
                'language' => $transcript['language'] ?? null,
                'duration_seconds' => $transcript['duration_seconds'] ?? null,
                'raw_text' => $transcript['raw_text'] ?? null,
                'validated_text' => $transcript['validated_text'] ?? null,
                'is_validated_by_ai' => (bool) ($transcript['is_validated_by_ai'] ?? false),
                'active_text_source' => empty($transcript['validated_text']) ? 'raw' : 'validated',
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function savePayloads(Video $video, array $payloads): void
    {
        foreach ($payloads as $payload) {
            $type = $payload['type'] ?? null;
            $typeStr = is_string($type) ? $type : '';
            $video->payloads()->create([
                'type' => $typeStr,
                'payload' => is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
            ]);
        }
    }

    /** @param  list<array<string, mixed>>  $files */
    private function markRenderedCuts(array $files): void
    {
        foreach ($files as $file) {
            $cutUuid = $file['cut_id'] ?? null;
            if (! is_string($cutUuid)) {
                continue;
            }

            if ($cutUuid === '') {
                continue;
            }

            $update = [
                'status_id' => Status::idFor('completed'),
                'rendered_at' => now(),
            ];

            // Metadados gerados pela IA na renderização (título/descrição/hashtags).
            if (isset($file['title']) && is_string($file['title']) && $file['title'] !== '') {
                $update['title'] = $file['title'];
            }

            if (isset($file['description']) && is_string($file['description']) && $file['description'] !== '') {
                $update['description'] = $file['description'];
            }

            if (isset($file['hashtags']) && is_array($file['hashtags'])) {
                $update['hashtags'] = array_values(array_filter(
                    $file['hashtags'],
                    static fn ($tag): bool => is_string($tag) && $tag !== '',
                ));
            }

            Cut::query()->where('uuid', $cutUuid)->update($update);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function isFailure(array $payload): bool
    {
        $event = $payload['event'] ?? '';
        $eventStr = is_string($event) ? $event : '';

        return ($payload['status'] ?? null) === 'failed'
            || str_ends_with($eventStr, '.failed');
    }
}
