<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Jobs\StartCutRenderJob;
use App\Livewire\Uploads\Show;
use App\Models\File;
use App\Models\Video;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('persists a cut with integer bounds and draft status', function (): void {
    $video = Video::factory()->ready()->create();

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('addCut', 5.0, 30.0);

    $cut = $video->cuts()->sole();

    expect($cut->start_seconds)->toBe(5)
        ->and($cut->end_seconds)->toBe(30)
        ->and($cut->status)->toBe(VideoCutStatusEnum::Draft)
        ->and($cut->is_ai_generated)->toBeFalse()
        ->and($cut->uuid)->not->toBeEmpty();
});

it('rejects a cut that collapses after the integer cast', function (): void {
    $video = Video::factory()->ready()->create();

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('addCut', 1.2, 1.8);

    expect($video->cuts()->count())->toBe(0);
});

it('rejects a cut longer than the maximum duration', function (): void {
    $video = Video::factory()->ready()->create(['duration_seconds' => 400]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('addCut', 0.0, 181.0);

    expect($video->cuts()->count())->toBe(0);
});

it('rejects a duplicate cut with the same bounds', function (): void {
    $video = Video::factory()->ready()->create();
    $video->cuts()->create(['start_seconds' => 5, 'end_seconds' => 30]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('addCut', 5.0, 30.0);

    expect($video->cuts()->count())->toBe(1);
});

it('rejects a cut beyond the video duration', function (): void {
    $video = Video::factory()->ready()->create();

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('addCut', 0.0, 41.0);

    expect($video->cuts()->count())->toBe(0);
});

it('soft deletes a cut and ignores cuts of other videos', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create();
    $other = Video::factory()->ready()->create();
    $cut = $video->cuts()->create(['start_seconds' => 1, 'end_seconds' => 10]);
    $foreign = $other->cuts()->create(['start_seconds' => 1, 'end_seconds' => 10]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('removeCut', $cut->id)
        ->call('removeCut', $foreign->id);

    expect($cut->fresh()?->trashed())->toBeTrue()
        ->and($foreign->fresh()?->trashed())->toBeFalse();
});

it('does not delete a cut while it is generating', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create();
    $cut = $video->cuts()->create([
        'start_seconds' => 1,
        'end_seconds' => 10,
        'status' => VideoCutStatusEnum::Generating,
    ]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('removeCut', $cut->id);

    expect($cut->fresh()?->trashed())->toBeFalse();
});

it('does not delete a cut while an edit render is generating', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create();
    $cut = $video->cuts()->create([
        'start_seconds' => 1,
        'end_seconds' => 10,
        'status' => VideoCutStatusEnum::Ready,
    ]);
    VideoCutEdit::query()->create([
        'video_cut_id' => $cut->id,
        'mode' => 'vertical',
        'keyframes' => [],
        'render_status' => VideoCutStatusEnum::Generating,
    ]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('removeCut', $cut->id);

    expect($cut->fresh()?->trashed())->toBeFalse();
});

it('removes unposted reframe shorts together with the cut', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create();
    $cut = $video->cuts()->create([
        'start_seconds' => 1,
        'end_seconds' => 10,
        'status' => VideoCutStatusEnum::Ready,
    ]);
    $edit = VideoCutEdit::query()->create([
        'video_cut_id' => $cut->id,
        'mode' => 'vertical',
        'keyframes' => [],
        'render_status' => VideoCutStatusEnum::Ready,
    ]);
    $unposted = YoutubeShort::query()->create([
        'youtube_id' => 'reframe-'.$edit->uuid,
        'video_path' => 'videos/x/edits/y/vertical.mp4',
        'downloaded_at' => now(),
    ]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('removeCut', $cut->id);

    expect($cut->fresh()?->trashed())->toBeTrue()
        ->and(YoutubeShort::query()->whereKey($unposted->id)->exists())->toBeFalse();
});

it('wipes the s3 artifacts and file rows when deleting a ready cut', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create();
    $cut = $video->cuts()->create([
        'start_seconds' => 1,
        'end_seconds' => 10,
        'status' => VideoCutStatusEnum::Ready,
    ]);
    Storage::disk('s3')->put($cut->clipPath(), 'clip');
    Storage::disk('s3')->put($cut->transcriptPath(), '{}');
    $file = $cut->files()->create([
        'video_id' => $video->id,
        'type' => File::CLIP,
        'path' => $cut->clipPath(),
    ]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('removeCut', $cut->id);

    expect($cut->fresh()?->trashed())->toBeTrue()
        ->and(Storage::disk('s3')->exists($cut->clipPath()))->toBeFalse()
        ->and(Storage::disk('s3')->exists($cut->transcriptPath()))->toBeFalse()
        ->and(File::query()->whereKey($file->id)->exists())->toBeFalse();
});

it('claims the cut and dispatches the render job exactly once', function (): void {
    Bus::fake([StartCutRenderJob::class]);
    $video = Video::factory()->ready()->create();
    $cut = $video->cuts()->create([
        'start_seconds' => 1,
        'end_seconds' => 10,
        'transcription_status' => TranscriptionStatusEnum::Failed,
        'error' => 'sobra anterior',
    ]);

    $component = Livewire::actingAs($video->user)->test(Show::class, ['uuid' => $video->uuid]);

    $component->call('generateCut', $cut->id);
    $component->call('generateCut', $cut->id);

    $cut->refresh();

    expect($cut->status)->toBe(VideoCutStatusEnum::Generating)
        ->and($cut->transcription_status)->toBeNull()
        ->and($cut->error)->toBeNull();

    Bus::assertDispatchedTimes(StartCutRenderJob::class, 1);
});
