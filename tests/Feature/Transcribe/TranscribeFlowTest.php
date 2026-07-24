<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatusEnum;
use App\Jobs\StartTranscribeJob;
use App\Models\File;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['services.observability.token' => 'test-token']);
    $this->headers = ['X-Observability-Token' => 'test-token'];
});

it('dispatches the transcription and marks it processing when the HLS finishes', function (): void {
    Bus::fake([StartTranscribeJob::class]);

    $video = Video::factory()->packaging()->create();

    $this->postJson('/api/webhook/hls', [
        'video_uuid' => $video->uuid,
        'status' => 'done',
        'duration_seconds' => 40,
        'width' => 1280,
        'height' => 720,
        'hash' => md5('x'),
        'renditions' => ['360p'],
        'audio' => true,
    ], $this->headers)->assertOk();

    expect($video->fresh()?->transcription_status)->toBe(TranscriptionStatusEnum::Processing);
    Bus::assertDispatched(StartTranscribeJob::class);
});

it('does not transcribe when the video has no audio', function (): void {
    Bus::fake([StartTranscribeJob::class]);

    $video = Video::factory()->packaging()->create();

    $this->postJson('/api/webhook/hls', [
        'video_uuid' => $video->uuid,
        'status' => 'done',
        'duration_seconds' => 40,
        'width' => 1280,
        'height' => 720,
        'hash' => md5('x'),
        'renditions' => ['360p'],
        'audio' => false,
    ], $this->headers)->assertOk();

    expect($video->fresh()?->transcription_status)->toBeNull();
    Bus::assertNotDispatched(StartTranscribeJob::class);
});

it('refuses the transcribe webhook without the shared token', function (): void {
    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Processing]);

    $this->postJson('/api/webhook/transcribe', [
        'uuid' => $video->uuid,
        'status' => 'done',
    ])->assertUnauthorized();
});

it('stores the transcript and marks it ready on a done webhook', function (): void {
    Storage::fake('s3');

    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Processing]);
    $transcript = ['language' => 'pt', 'segments' => [['start' => 0.5, 'end' => 1.2, 'text' => 'olá', 'words' => []]]];

    $this->postJson('/api/webhook/transcribe', [
        'uuid' => $video->uuid,
        'status' => 'done',
        'transcript' => $transcript,
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'ready']);

    $video->refresh();

    expect($video->transcription_status)->toBe(TranscriptionStatusEnum::Ready)
        ->and($video->file(File::TRANSCRIPT)?->path)->toBe($video->transcriptPath());

    Storage::disk('s3')->assertExists($video->transcriptPath());
    expect(json_decode((string) Storage::disk('s3')->get($video->transcriptPath()), true))->toBe($transcript);
});

it('marks the transcription failed on a failed webhook', function (): void {
    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Processing]);

    $this->postJson('/api/webhook/transcribe', [
        'uuid' => $video->uuid,
        'status' => 'failed',
        'error' => 'whisper morreu',
    ], $this->headers)->assertOk();

    expect($video->fresh()?->transcription_status)->toBe(TranscriptionStatusEnum::Failed);
});

it('serves the transcript as WebVTT for the owner', function (): void {
    Storage::fake('s3');

    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Ready]);
    $video->files()->create(['type' => File::TRANSCRIPT, 'path' => $video->transcriptPath(), 'mime_type' => 'application/json']);
    Storage::disk('s3')->put($video->transcriptPath(), (string) json_encode([
        'segments' => [
            ['start' => 0.0, 'end' => 2.4, 'text' => 'olá mundo'],
            ['start' => 2.4, 'end' => 2.4, 'text' => 'cue inválida'],
        ],
    ]));

    $this->actingAs($video->user)
        ->get(route('uploads.subtitles', $video->uuid))
        ->assertOk()
        ->assertSee('WEBVTT', false)
        ->assertSee('00:00:00.000 --> 00:00:02.400', false)
        ->assertSee('olá mundo', false)
        ->assertDontSee('cue inválida', false);
});

it('404s the subtitles for a non-owner', function (): void {
    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Ready]);
    $video->files()->create(['type' => File::TRANSCRIPT, 'path' => $video->transcriptPath()]);

    $this->actingAs(User::factory()->create())
        ->get(route('uploads.subtitles', $video->uuid))
        ->assertNotFound();
});

it('is idempotent once the transcription is terminal', function (): void {
    Storage::fake('s3');

    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Ready]);

    $this->postJson('/api/webhook/transcribe', [
        'uuid' => $video->uuid,
        'status' => 'done',
        'transcript' => ['language' => 'pt', 'segments' => []],
    ], $this->headers)->assertOk()->assertJson(['status' => 'already-finished']);

    expect(File::query()->where('type', File::TRANSCRIPT)->count())->toBe(0);
});
