<?php

declare(strict_types=1);

use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['services.observability.token' => 'test-token']);
    $this->headers = ['X-Observability-Token' => 'test-token'];
});

it('refuses a webhook without the shared token', function (): void {
    $video = Video::factory()->packaging()->create();

    $this->postJson('/api/hls/webhook', [
        'uuid' => $video->hls_remote_id,
        'status' => 'done',
    ])->assertUnauthorized();

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Packaging);
});

it('answers 404 for an unknown job', function (): void {
    $this->postJson('/api/hls/webhook', [
        'uuid' => 'nao-existe',
        'status' => 'done',
    ], $this->headers)->assertNotFound()->assertJson(['status' => 'unknown-job']);
});

it('promotes the video to ready with the reported metadata', function (): void {
    $video = Video::factory()->packaging()->create();

    $this->postJson('/api/hls/webhook', [
        'uuid' => $video->hls_remote_id,
        'status' => 'done',
        'duration_seconds' => 40,
        'width' => 1280,
        'height' => 720,
        'hash' => md5('x'),
        'renditions' => ['360p', '720p'],
        'poster' => true,
    ], $this->headers)->assertOk()->assertJson(['status' => 'ready']);

    $video->refresh();

    expect($video->status)->toBe(VideoStatusEnum::Ready)
        ->and($video->progress)->toBe(100)
        ->and($video->duration_seconds)->toBe(40)
        ->and($video->height)->toBe(720)
        ->and($video->renditions)->toBe(['360p', '720p'])
        ->and($video->hls_path)->toBe($video->hlsPrefix())
        ->and($video->poster_path)->toBe($video->hlsPrefix().'/poster.jpg')
        ->and($video->ready_at)->not->toBeNull();
});

it('is idempotent once the video reached a terminal status', function (): void {
    $video = Video::factory()->ready()->create();

    $this->postJson('/api/hls/webhook', [
        'uuid' => $video->hls_remote_id,
        'status' => 'failed',
        'error' => 'retry atrasado',
    ], $this->headers)->assertOk()->assertJson(['status' => 'already-finished']);

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Ready);
});

it('never lets progress go backwards', function (): void {
    $video = Video::factory()->packaging()->create(['progress' => 60]);

    $this->postJson('/api/hls/webhook', [
        'uuid' => $video->hls_remote_id,
        'status' => 'progress',
        'progress' => 20,
    ], $this->headers)->assertOk();

    expect($video->fresh()?->progress)->toBe(60);

    $this->postJson('/api/hls/webhook', [
        'uuid' => $video->hls_remote_id,
        'status' => 'progress',
        'progress' => 80,
    ], $this->headers)->assertOk();

    expect($video->fresh()?->progress)->toBe(80);
});

it('keeps the source when packaging merely failed', function (): void {
    Storage::fake('s3');

    $video = Video::factory()->packaging()->create();
    Storage::disk('s3')->put($video->path(), 'conteudo');

    $this->postJson('/api/hls/webhook', [
        'uuid' => $video->hls_remote_id,
        'status' => 'failed',
        'error' => 'ffmpeg morreu',
    ], $this->headers)->assertOk();

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Failed);
    Storage::disk('s3')->assertExists($video->path());
});

it('drops the source when the file was not a video', function (): void {
    Storage::fake('s3');

    $video = Video::factory()->packaging()->create();
    Storage::disk('s3')->put($video->path(), 'nao e video');

    $this->postJson('/api/hls/webhook', [
        'uuid' => $video->hls_remote_id,
        'status' => 'rejected',
        'error' => 'O arquivo enviado não é um vídeo legível.',
    ], $this->headers)->assertOk();

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Rejected);
    Storage::disk('s3')->assertMissing($video->path());
});
