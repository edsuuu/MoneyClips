<?php

declare(strict_types=1);

use App\Enums\VideoStatusEnum;
use App\Models\File;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['services.observability.token' => 'test-token']);
    $this->headers = ['X-Observability-Token' => 'test-token'];
});

it('refuses a webhook without the shared token', function (): void {
    $video = Video::factory()->packaging()->create();

    $this->postJson('/api/hls/webhook', [
        'video_uuid' => $video->uuid,
        'status' => 'done',
    ])->assertUnauthorized();

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Packaging);
});

it('answers 404 for an unknown job', function (): void {
    $this->postJson('/api/hls/webhook', [
        'video_uuid' => 'nao-existe',
        'status' => 'done',
    ], $this->headers)->assertNotFound()->assertExactJson(['status' => 'unknown-job']);
});

it('promotes the video to ready and records the artifacts', function (): void {
    $video = Video::factory()->packaging()->create();

    $this->postJson('/api/hls/webhook', [
        'video_uuid' => $video->uuid,
        'status' => 'done',
        'duration_seconds' => 40,
        'width' => 1280,
        'height' => 720,
        'hash' => md5('x'),
        'renditions' => ['360p', '720p'],
        'poster' => true,
        'audio' => true,
        'storyboard' => ['cols' => 10, 'rows' => 13, 'interval' => 6, 'tile_width' => 160, 'tile_height' => 90],
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'ready']);

    $video->refresh();
    $hls = $video->file(File::HLS);

    expect($video->status)->toBe(VideoStatusEnum::Ready)
        ->and($video->progress)->toBe(100)
        ->and($video->duration_seconds)->toBe(40)
        ->and($video->height)->toBe(720)
        ->and($hls?->path)->toBe($video->masterPlaylistPath())
        ->and($hls?->meta['renditions'])->toBe(['360p', '720p'])
        ->and($video->file(File::POSTER))->not->toBeNull()
        ->and($video->file(File::AUDIO))->not->toBeNull()
        ->and($video->file(File::STORYBOARD)?->meta['cols'])->toBe(10)
        ->and($video->ready_at)->not->toBeNull();
});

it('is idempotent once the video reached a terminal status', function (): void {
    $video = Video::factory()->ready()->create();

    $this->postJson('/api/hls/webhook', [
        'video_uuid' => $video->uuid,
        'status' => 'failed',
        'error' => 'retry atrasado',
    ], $this->headers)->assertOk()->assertJson(['status' => 'already-finished']);

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Ready);
});

it('never lets progress go backwards', function (): void {
    $video = Video::factory()->packaging()->create(['progress' => 60]);

    $this->postJson('/api/hls/webhook', [
        'video_uuid' => $video->uuid,
        'status' => 'progress',
        'progress' => 20,
    ], $this->headers)->assertOk();

    expect($video->fresh()?->progress)->toBe(60);

    $this->postJson('/api/hls/webhook', [
        'video_uuid' => $video->uuid,
        'status' => 'progress',
        'progress' => 80,
    ], $this->headers)->assertOk();

    expect($video->fresh()?->progress)->toBe(80);
});

it('keeps the source when packaging merely failed', function (): void {
    Storage::fake('s3');

    $video = Video::factory()->packaging()->create();
    Storage::disk('s3')->put($video->originalPath(), 'conteudo');

    $this->postJson('/api/hls/webhook', [
        'video_uuid' => $video->uuid,
        'status' => 'failed',
        'error' => 'ffmpeg morreu',
    ], $this->headers)->assertOk();

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Failed);
    Storage::disk('s3')->assertExists($video->originalPath());
});

it('drops the source when the file was not a video', function (): void {
    Storage::fake('s3');

    $video = Video::factory()->packaging()->create();
    Storage::disk('s3')->put($video->originalPath(), 'nao e video');

    $this->postJson('/api/hls/webhook', [
        'video_uuid' => $video->uuid,
        'status' => 'rejected',
        'error' => 'O arquivo enviado não é um vídeo legível.',
    ], $this->headers)->assertOk();

    expect($video->fresh()?->status)->toBe(VideoStatusEnum::Rejected);
    Storage::disk('s3')->assertMissing($video->originalPath());
});
