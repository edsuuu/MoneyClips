<?php

declare(strict_types=1);

use App\Enums\VideoStatusEnum;
use App\Jobs\StartHLSPackagingJob;
use App\Models\File;
use App\Models\Video;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config(['services.observability.token' => 'test-token']);
    $this->headers = ['X-Observability-Token' => 'test-token'];
    Bus::fake([StartHLSPackagingJob::class]);
});

it('refuses a webhook without the shared token', function (): void {
    $video = Video::factory()->downloading()->create();

    $this->postJson('/api/webhook/download-video', [
        'video_uuid' => $video->uuid,
        'status' => 'completed',
    ])->assertUnauthorized();

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Downloading);
});

it('answers 404 for an unknown job', function (): void {
    $this->postJson('/api/webhook/download-video', [
        'video_uuid' => 'nao-existe',
        'status' => 'completed',
    ], $this->headers)->assertNotFound()->assertExactJson(['status' => 'unknown-job']);
});

it('records the original file and queues the packaging on completion', function (): void {
    $video = Video::factory()->downloading()->create();

    $this->postJson('/api/webhook/download-video', [
        'video_uuid' => $video->uuid,
        'status' => 'completed',
        'size_bytes' => 123456,
        'title' => 'Meu vídeo',
        'duration_seconds' => 90,
        'width' => 1920,
        'height' => 1080,
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'download-recorded']);

    $video->refresh();
    $original = $video->file(File::ORIGINAL);

    expect($video->status)->toBe(VideoStatusEnum::Uploaded)
        ->and($video->duration_seconds)->toBe(90)
        ->and($video->height)->toBe(1080)
        ->and($original?->path)->toBe($video->originalPath())
        ->and($original?->size)->toBe(123456)
        ->and($original?->mime_type)->toBe('video/mp4');

    Bus::assertDispatched(StartHLSPackagingJob::class);
});

it('claims atomically: a duplicated completion changes nothing', function (): void {
    $video = Video::factory()->downloading()->create();

    $payload = [
        'video_uuid' => $video->uuid,
        'status' => 'completed',
        'size_bytes' => 123456,
    ];

    $this->postJson('/api/webhook/download-video', $payload, $this->headers)->assertOk();
    $this->postJson('/api/webhook/download-video', $payload, $this->headers)
        ->assertOk()->assertExactJson(['status' => 'already-finished']);

    expect($video->files()->where('type', File::ORIGINAL)->count())->toBe(1);
    Bus::assertDispatchedTimes(StartHLSPackagingJob::class, 1);
});

it('marks the video as failed with the reported error', function (): void {
    $video = Video::factory()->downloading()->create();

    $this->postJson('/api/webhook/download-video', [
        'video_uuid' => $video->uuid,
        'status' => 'failed',
        'error' => 'yt-dlp morreu',
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'failure-recorded']);

    $video->refresh();

    expect($video->status)->toBe(VideoStatusEnum::Failed)
        ->and($video->error)->toBe('yt-dlp morreu');

    Bus::assertNotDispatched(StartHLSPackagingJob::class);
});

it('ignores a late failure once the video already moved on', function (): void {
    $video = Video::factory()->ready()->create();

    $this->postJson('/api/webhook/download-video', [
        'video_uuid' => $video->uuid,
        'status' => 'failed',
        'error' => 'retry atrasado',
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'already-finished']);

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Ready);
});
