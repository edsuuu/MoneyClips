<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Jobs\StartFaceTrackingJob;
use App\Jobs\StartVideoCutEditRenderJob;
use App\Livewire\VideoEditor\Index;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use App\Services\Video\VideoCutEditRenderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Storage::fake('s3');
});

function makeEditForTracking(User $owner, ?string $trackingStatus = null): VideoCutEdit
{
    $video = Video::factory()->ready()->create(['user_id' => $owner->id]);

    $cut = $video->cuts()->create([
        'start_seconds' => 5,
        'end_seconds' => 65,
        'status' => VideoCutStatusEnum::Ready,
    ]);

    return VideoCutEdit::factory()->create([
        'video_cut_id' => $cut->id,
        'tracking_status' => $trackingStatus,
    ]);
}

it('claims the edit and dispatches the tracking job exactly once', function (): void {
    Bus::fake([StartFaceTrackingJob::class]);
    $edit = makeEditForTracking($this->user);

    Livewire::test(Index::class, ['uuid' => $edit->videoCut?->uuid])
        ->set('editId', $edit->id)
        ->call('generateTracking')
        ->assertReturned(TranscriptionStatusEnum::Processing->value);

    expect($edit->fresh()?->tracking_status)->toBe(TranscriptionStatusEnum::Processing);
    Bus::assertDispatchedTimes(StartFaceTrackingJob::class, 1);
});

it('does not dispatch again while a tracking run is in flight', function (): void {
    Bus::fake([StartFaceTrackingJob::class]);
    $edit = makeEditForTracking($this->user, TranscriptionStatusEnum::Processing->value);

    Livewire::test(Index::class, ['uuid' => $edit->videoCut?->uuid])
        ->set('editId', $edit->id)
        ->call('generateTracking')
        ->assertReturned(null);

    Bus::assertNotDispatched(StartFaceTrackingJob::class);
});

it('hands the editor the tracking status it needs to render the button', function (): void {
    $edit = makeEditForTracking($this->user, TranscriptionStatusEnum::Ready->value);

    Livewire::test(Index::class, ['uuid' => $edit->videoCut?->uuid])
        ->set('editId', $edit->id)
        ->assertViewHas('editorPayload', fn (array $payload): bool => $payload['trackingStatus'] === TranscriptionStatusEnum::Ready->value);
});

it('labels each transcript segment with the speaker that covers it before rendering', function (): void {
    $edit = makeEditForTracking($this->user);
    $edit->update([
        'render_status' => VideoCutStatusEnum::Generating->value,
        'settings' => ['version' => 1, 'background' => '#000000', 'captions' => true, 'speakerColors' => [1 => '#ffd166', 2 => '#4cc9f0']],
    ]);

    $cut = $edit->videoCut;
    $cut->update(['transcription_status' => TranscriptionStatusEnum::Ready]);

    Storage::disk('s3')->put($cut->clipPath(), 'fake-clip');
    Storage::disk('s3')->put($cut->transcriptPath(), json_encode([
        'language' => 'pt',
        'segments' => [
            ['start' => 0.0, 'end' => 2.0, 'text' => 'oi', 'words' => []],
            ['start' => 2.5, 'end' => 5.0, 'text' => 'tudo bem', 'words' => []],
            ['start' => 90.0, 'end' => 95.0, 'text' => 'fora da timeline', 'words' => []],
        ],
    ], JSON_THROW_ON_ERROR));
    Storage::disk('s3')->put($cut->speakersPath(), json_encode([
        'speakers' => [
            ['start' => 0.0, 'end' => 2.2, 'speaker' => 1],
            ['start' => 2.2, 'end' => 6.0, 'speaker' => 2],
        ],
    ], JSON_THROW_ON_ERROR));

    Http::fake(['*' => Http::response(['uuid' => 'job-1'], 202)]);

    new StartVideoCutEditRenderJob($edit->id)->handle(resolve(VideoCutEditRenderService::class));

    Http::assertSent(function ($request): bool {
        $segments = $request->data()['transcript']['segments'];

        return $segments[0]['speaker'] === 1
            && $segments[1]['speaker'] === 2
            && ! array_key_exists('speaker', $segments[2])
            && $request->data()['settings']['speakerColors'] === [1 => '#ffd166', 2 => '#4cc9f0'];
    });
});

it('leaves the transcript untouched when no tracking ever ran', function (): void {
    $edit = makeEditForTracking($this->user);
    $edit->update([
        'render_status' => VideoCutStatusEnum::Generating->value,
        'settings' => ['version' => 1, 'background' => '#000000', 'captions' => true],
    ]);

    $cut = $edit->videoCut;
    $cut->update(['transcription_status' => TranscriptionStatusEnum::Ready]);

    Storage::disk('s3')->put($cut->clipPath(), 'fake-clip');
    Storage::disk('s3')->put($cut->transcriptPath(), json_encode([
        'language' => 'pt',
        'segments' => [['start' => 0.0, 'end' => 2.0, 'text' => 'oi', 'words' => []]],
    ], JSON_THROW_ON_ERROR));

    Http::fake(['*' => Http::response(['uuid' => 'job-1'], 202)]);

    new StartVideoCutEditRenderJob($edit->id)->handle(resolve(VideoCutEditRenderService::class));

    Http::assertSent(fn ($request): bool => ! array_key_exists('speaker', $request->data()['transcript']['segments'][0]));
});

it('marks the tracking as failed when the job cannot reach the service', function (): void {
    $edit = makeEditForTracking($this->user, TranscriptionStatusEnum::Processing->value);

    new StartFaceTrackingJob($edit->id)->failed(new RuntimeException('media fora do ar'));

    expect($edit->fresh()?->tracking_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($edit->fresh()?->tracking_error)->toBe('media fora do ar');
});

it('never lets a late failure overwrite keyframes that already arrived', function (): void {
    $edit = makeEditForTracking($this->user, TranscriptionStatusEnum::Ready->value);

    new StartFaceTrackingJob($edit->id)->failed(new RuntimeException('timeout tardio'));

    expect($edit->fresh()?->tracking_status)->toBe(TranscriptionStatusEnum::Ready)
        ->and($edit->fresh()?->tracking_error)->toBeNull();
});

it('requires a saved edit before tracking anything', function (): void {
    Bus::fake([StartFaceTrackingJob::class]);
    $cut = VideoCut::query()->firstOrCreate([
        'video_id' => Video::factory()->ready()->create(['user_id' => $this->user->id])->id,
        'start_seconds' => 1,
        'end_seconds' => 30,
    ], ['status' => VideoCutStatusEnum::Ready]);

    Livewire::test(Index::class, ['uuid' => $cut->uuid])
        ->call('generateTracking')
        ->assertReturned(null);

    Bus::assertNotDispatched(StartFaceTrackingJob::class);
});
