<?php

declare(strict_types=1);

namespace App\Services\VideoProcessor;

use App\Models\Cut;
use App\Models\File;
use App\Models\ProcessingJob;
use App\Models\Status;
use App\Models\Transcript;
use App\Models\Video;
use App\Models\VideoPayload;
use App\Services\Status\StatusService;
use App\Services\VideoProcessor\Contracts\VideoProcessorProviderInterface;
use App\Services\VideoProcessor\Data\IngestVideoData;
use App\Services\VideoProcessor\Data\RecommendCutsData;
use App\Services\VideoProcessor\Data\RenderCutsData;
use App\Services\VideoProcessor\Data\SubtitleFullData;
use Illuminate\Support\Collection;

/**
 * Regra de negócio da integração com a API de processamento de vídeo.
 * - cria/atualiza processing_jobs
 * - transiciona status (via StatusService)
 * - monta os DTOs e delega as chamadas HTTP ao provider (contrato)
 *
 * Nenhuma chamada HTTP é feita aqui diretamente — isso é responsabilidade do
 * HttpVideoProcessorProvider injetado.
 */
final readonly class VideoProcessorService
{
    public function __construct(
        private VideoProcessorProviderInterface $provider,
        private StatusService $status,
    ) {}

    /** Etapa 1: baixar + transcrever. */
    public function startIngest(Video $video): ProcessingJob
    {
        $job = $this->createJob($video, 'ingest');

        $data = new IngestVideoData(
            videoId: $video->uuid,
            url: $video->url,
            callbackUrl: $this->callbackUrl(),
            callbackToken: $this->callbackToken(),
            transcribe: true,
            validateTranscript: true,
            uploadOriginalToMinio: true,
        );

        $this->savePayload($video, $job, 'ingest_request', $data->toArray());
        $this->status->transition($video, 'downloading', 'Ingestão enviada à API de processamento');
        $video->update(['current_stage' => 'ingest']);

        $response = $this->provider->ingest($data);
        $this->bindExternalJob($job, $response);

        return $job;
    }

    /** Etapa 2: legendar o vídeo completo com a transcrição confirmada. */
    public function startSubtitleFull(Video $video): ProcessingJob
    {
        $video->files()->where('type', 'legendado')->delete();

        $original = $video->fileOfType('original');
        abort_if(! $original instanceof File, 422, 'Vídeo original não encontrado no MinIO.');

        $transcriptJson = $this->transcriptJson($video);
        $transcript = $video->transcript;
        $transcriptText = $transcript instanceof Transcript ? $transcript->activeText() : null;

        $job = $this->createJob($video, 'subtitle_full');

        $data = new SubtitleFullData(
            sourcePath: $original->path,
            outputPath: sprintf('videos/%s/full/legendado.mp4', $video->uuid),
            transcriptJson: $transcriptJson,
            callbackUrl: $this->callbackUrl(),
            transcriptText: $transcriptText,
            bucket: is_string($bucketVal = config('video-processor.storage_bucket')) ? $bucketVal : null,
            callbackToken: $this->callbackToken(),
        );

        $this->savePayload($video, $job, 'subtitle_request', $data->toArray());
        $this->status->transition($video, 'subtitling_full', 'Legendagem do vídeo completo enviada');
        $video->update(['current_stage' => 'subtitle_full']);

        $response = $this->provider->subtitleFull($video->uuid, $data);
        $this->bindExternalJob($job, $response);

        return $job;
    }

    /**
     * Etapa 3 (síncrona): pedir à IA para recomendar cortes.
     *
     * @param  array<string, mixed>  $constraints
     * @return list<array<string, mixed>>
     */
    public function recommendCuts(Video $video, ?string $userPrompt = null, array $constraints = []): array
    {
        $this->status->transition($video, 'recommending_cuts', 'Solicitando recomendação de cortes');

        $data = new RecommendCutsData(
            transcriptJson: $this->transcriptJson($video),
            video: ['title' => $video->title, 'duration_seconds' => $video->duration_seconds],
            constraints: $constraints,
            userPrompt: $userPrompt,
        );

        $response = $this->provider->recommendCuts($video->uuid, $data);
        $cuts = $response['cuts'] ?? [];

        $this->savePayload($video, null, 'cuts_recommendation_result', $response);
        $this->status->transition($video, 'waiting_cuts', 'Cortes recomendados pela IA');

        /** @var list<array<string, mixed>> $cutsList */
        $cutsList = is_array($cuts) ? array_values($cuts) : [];

        return $cutsList;
    }

    /**
     * Etapa 4: renderizar os cortes aprovados.
     *
     * @param  Collection<int, Cut>  $cuts
     */
    public function startRenderCuts(Video $video, Collection $cuts): ProcessingJob
    {
        $original = $video->fileOfType('original');
        abort_if(! $original instanceof File, 422, 'Vídeo original não encontrado no MinIO.');

        $job = $this->createJob($video, 'render_cuts');

        $payloadCuts = array_values($cuts->map(fn (Cut $cut): array => [
            'cut_id' => $cut->uuid,
            'name' => $cut->name,
            'type' => $cut->type,
            'start_seconds' => $cut->start_seconds,
            'end_seconds' => $cut->end_seconds,
            'vertical' => true,
            'face_tracking' => true,
            'output_path' => sprintf('videos/%s/cuts/%s.mp4', $video->uuid, $cut->type),
        ])->all());

        $data = new RenderCutsData(
            sourcePath: $original->path,
            transcriptJson: $this->transcriptJson($video),
            cuts: $payloadCuts,
            callbackUrl: $this->callbackUrl(),
            bucket: is_string($bucketVal = config('video-processor.storage_bucket')) ? $bucketVal : null,
            callbackToken: $this->callbackToken(),
            video: ['title' => $video->title, 'duration_seconds' => $video->duration_seconds],
        );

        $this->savePayload($video, $job, 'render_request', $data->toArray());
        $this->status->transition($video, 'cutting', 'Renderização de cortes enviada');
        $video->update(['current_stage' => 'render_cuts']);

        $response = $this->provider->renderCuts($video->uuid, $data);
        $this->bindExternalJob($job, $response);

        return $job;
    }

    private function createJob(Video $video, string $type): ProcessingJob
    {
        return ProcessingJob::query()->create([
            'video_id' => $video->id,
            'type' => $type,
            'provider' => 'video_processor',
            'status_id' => Status::idFor('queued'),
            'progress' => 0,
            'stage' => $type,
        ]);
    }

    /** @param  array<string, mixed>  $response */
    private function bindExternalJob(ProcessingJob $job, array $response): void
    {
        $job->update([
            'external_job_id' => $response['job_id'] ?? null,
            'status_id' => Status::idFor('processing'),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function savePayload(Video $video, ?ProcessingJob $job, string $type, array $payload): VideoPayload
    {
        return VideoPayload::query()->create([
            'video_id' => $video->id,
            'processing_job_id' => $job?->id,
            'type' => $type,
            'payload' => $payload,
        ]);
    }

    /**
     * Recupera o transcript_json com timestamps (preferindo validado > bruto)
     * a partir dos video_payloads. Necessário para legendas palavra-a-palavra.
     *
     * @return array<string, mixed>
     */
    private function transcriptJson(Video $video): array
    {
        $payload = $video->payloads()
            ->whereIn('type', ['transcript_validated', 'transcript_raw'])
            ->orderByRaw("CASE type WHEN 'transcript_validated' THEN 0 ELSE 1 END")
            ->latest()
            ->first();

        if ($payload instanceof VideoPayload) {
            /** @var array<string, mixed> $data */
            $data = $payload->payload;

            return $data;
        }

        return [];
    }

    private function callbackUrl(): string
    {
        $url = config('video-processor.callback_url');

        return is_string($url) ? $url : '';
    }

    private function callbackToken(): ?string
    {
        $token = config('video-processor.callback_token');
        $tokenStr = is_string($token) ? $token : '';

        return $tokenStr !== '' ? $tokenStr : null;
    }
}
