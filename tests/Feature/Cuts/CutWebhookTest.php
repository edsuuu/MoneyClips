<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Jobs\StartCutTranscribeJob;
use App\Models\File;
use App\Models\Video;
use App\Models\VideoCut;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

function makeGeneratingCut(): VideoCut
{
    $video = Video::factory()->ready()->create();

    return $video->cuts()->create([
        'start_seconds' => 5,
        'end_seconds' => 20,
        'status' => VideoCutStatusEnum::Generating,
    ]);
}

beforeEach(function (): void {
    config(['services.observability.token' => 'test-token']);
    $this->headers = ['X-Observability-Token' => 'test-token'];
    Bus::fake([StartCutTranscribeJob::class]);
});

it('refuses a cut webhook without the shared token', function (): void {
    $cut = makeGeneratingCut();

    $this->postJson('/api/webhook/cut', [
        'cut_uuid' => $cut->uuid,
        'status' => 'done',
    ])->assertUnauthorized();

    expect($cut->fresh()?->status)->toBe(VideoCutStatusEnum::Generating);
});

it('answers 404 for an unknown cut', function (): void {
    $this->postJson('/api/webhook/cut', [
        'cut_uuid' => 'nao-existe',
        'status' => 'done',
    ], $this->headers)->assertNotFound()->assertExactJson(['status' => 'unknown-job']);
});

it('records the clip artifacts and starts the transcription', function (): void {
    $cut = makeGeneratingCut();
    $video = $cut->video;
    $video->files()->create(['type' => File::AUDIO, 'path' => $video->audioPath(), 'mime_type' => 'audio/wav']);

    $this->postJson('/api/webhook/cut', [
        'cut_uuid' => $cut->uuid,
        'status' => 'done',
        'duration_seconds' => 15,
        'audio' => true,
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'ready']);

    $cut->refresh();
    $clip = $cut->files()->where('type', File::CLIP)->first();
    $clipAudio = $cut->files()->where('type', File::AUDIO)->first();

    expect($cut->status)->toBe(VideoCutStatusEnum::Ready)
        ->and($cut->transcription_status)->toBe(TranscriptionStatusEnum::Processing)
        ->and($clip?->path)->toBe($cut->clipPath())
        ->and($clip?->video_id)->toBe($video->id)
        ->and($clipAudio?->path)->toBe($cut->clipAudioPath())
        ->and($video->fresh(['files'])?->file(File::AUDIO)?->path)->toBe($video->audioPath());

    Bus::assertDispatched(StartCutTranscribeJob::class);
});

it('skips transcription when the clip has no audio', function (): void {
    $cut = makeGeneratingCut();

    $this->postJson('/api/webhook/cut', [
        'cut_uuid' => $cut->uuid,
        'status' => 'done',
        'audio' => false,
    ], $this->headers)->assertOk();

    $cut->refresh();

    expect($cut->status)->toBe(VideoCutStatusEnum::Ready)
        ->and($cut->transcription_status)->toBeNull()
        ->and($cut->files()->where('type', File::AUDIO)->exists())->toBeFalse();

    Bus::assertNotDispatched(StartCutTranscribeJob::class);
});

it('records a failure with its error', function (): void {
    $cut = makeGeneratingCut();

    $this->postJson('/api/webhook/cut', [
        'cut_uuid' => $cut->uuid,
        'status' => 'failed',
        'error' => 'ffmpeg morreu',
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'failure-recorded']);

    $cut->refresh();

    expect($cut->status)->toBe(VideoCutStatusEnum::Failed)
        ->and($cut->error)->toBe('ffmpeg morreu');
});

it('is idempotent once the cut reached a terminal status', function (): void {
    $cut = makeGeneratingCut();
    $cut->update(['status' => VideoCutStatusEnum::Ready]);

    $this->postJson('/api/webhook/cut', [
        'cut_uuid' => $cut->uuid,
        'status' => 'done',
        'audio' => true,
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'already-finished']);

    expect($cut->files()->count())->toBe(0);
});

it('stores the transcript of a cut through the transcribe webhook', function (): void {
    Storage::fake('s3');
    $cut = makeGeneratingCut();
    $cut->update(['status' => VideoCutStatusEnum::Ready, 'transcription_status' => TranscriptionStatusEnum::Processing]);

    $this->postJson('/api/webhook/transcribe', [
        'uuid' => $cut->uuid,
        'status' => 'done',
        'transcript' => ['language' => 'pt', 'segments' => [['start' => 0, 'end' => 1, 'text' => 'oi', 'words' => []]]],
    ], $this->headers)->assertOk()->assertExactJson(['status' => 'ready']);

    $cut->refresh();
    $transcript = $cut->files()->where('type', File::TRANSCRIPT)->first();

    expect($cut->transcription_status)->toBe(TranscriptionStatusEnum::Ready)
        ->and($transcript?->path)->toBe($cut->transcriptPath());

    Storage::disk('s3')->assertExists($cut->transcriptPath());
});
