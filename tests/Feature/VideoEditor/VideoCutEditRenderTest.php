<?php

declare(strict_types=1);

use App\Enums\VideoCutStatusEnum;
use App\Jobs\StartVideoCutEditRenderJob;
use App\Livewire\VideoEditor\Index;
use App\Models\File;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Bus::fake([StartVideoCutEditRenderJob::class]);
});

function makeRenderReadyCut(User $owner): VideoCut
{
    $video = Video::factory()->ready()->create(['user_id' => $owner->id]);

    return $video->cuts()->create([
        'start_seconds' => 5,
        'end_seconds' => 20,
        'status' => VideoCutStatusEnum::Ready,
    ]);
}

function makeEditForRender(User $owner, ?string $renderStatus = null): VideoCutEdit
{
    $cut = makeRenderReadyCut($owner);

    return VideoCutEdit::query()->create([
        'video_cut_id' => $cut->id,
        'source_meta' => ['width' => 1920, 'height' => 1080, 'duration' => 15.0],
        'mode' => 'vertical',
        'keyframes' => [['t' => 0.0, 'mode' => 'vertical', 'regions' => [['x' => 0.2, 'y' => 0.0, 'w' => 0.3164, 'h' => 1.0]]]],
        'settings' => ['version' => 1, 'background' => '#000000'],
        'render_status' => $renderStatus,
    ]);
}

it('claims the edit and dispatches the render job exactly once', function (): void {
    $edit = makeEditForRender($this->user);

    Livewire::test(Index::class, ['uuid' => $edit->videoCut?->uuid])
        ->set('editId', $edit->id)
        ->call('generateRender')
        ->assertReturned(VideoCutStatusEnum::Generating->value);

    expect($edit->fresh()?->render_status)->toBe(VideoCutStatusEnum::Generating);
    Bus::assertDispatchedTimes(StartVideoCutEditRenderJob::class, 1);
});

it('does not dispatch again while a render is in flight', function (): void {
    $edit = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);

    Livewire::test(Index::class, ['uuid' => $edit->videoCut?->uuid])
        ->set('editId', $edit->id)
        ->call('generateRender')
        ->assertReturned(null);

    Bus::assertNotDispatched(StartVideoCutEditRenderJob::class);
});

it('refuses to render without a saved edit', function (): void {
    $cut = makeRenderReadyCut($this->user);

    Livewire::test(Index::class, ['uuid' => $cut->uuid])
        ->call('generateRender')
        ->assertReturned(null);

    Bus::assertNotDispatched(StartVideoCutEditRenderJob::class);
});

it('finishes the render via webhook and puts the clip in the stock', function (): void {
    config(['services.observability.token' => 'test-token']);
    $edit = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);

    $this->postJson('/api/webhook/video-cut-edit', [
        'edit_uuid' => $edit->uuid,
        'status' => 'done',
    ], ['X-Observability-Token' => 'test-token'])->assertOk()->assertExactJson(['status' => 'ready']);

    $fresh = $edit->fresh();
    $short = YoutubeShort::query()->where('youtube_id', 'reframe-'.$edit->uuid)->first();

    expect($fresh?->render_status)->toBe(VideoCutStatusEnum::Ready)
        ->and(File::query()->where('video_cut_id', $edit->video_cut_id)->where('type', File::EDIT)->where('path', $edit->renderOutputPath())->exists())->toBeTrue()
        ->and($short)->not->toBeNull()
        ->and($short?->video_path)->toBe($edit->renderOutputPath())
        ->and($short?->downloaded_at)->not->toBeNull()
        ->and($fresh?->youtube_short_id)->toBe($short?->id);
});

it('marks the edit as failed when the parent cut was removed during the render', function (): void {
    config(['services.observability.token' => 'test-token']);
    $edit = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);
    $edit->videoCut?->delete();

    $this->postJson('/api/webhook/video-cut-edit', [
        'edit_uuid' => $edit->uuid,
        'status' => 'done',
    ], ['X-Observability-Token' => 'test-token'])->assertOk()->assertExactJson(['status' => 'failure-recorded']);

    expect($edit->fresh()?->render_status)->toBe(VideoCutStatusEnum::Failed)
        ->and(YoutubeShort::query()->where('youtube_id', 'reframe-'.$edit->uuid)->exists())->toBeFalse();
});

it('lets a stale generating render be claimed again', function (): void {
    $edit = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);
    VideoCutEdit::query()->whereKey($edit->id)->update(['updated_at' => now()->subMinutes(31)]);

    Livewire::test(Index::class, ['uuid' => $edit->videoCut?->uuid])
        ->set('editId', $edit->id)
        ->call('generateRender')
        ->assertReturned(VideoCutStatusEnum::Generating->value);

    Bus::assertDispatchedTimes(StartVideoCutEditRenderJob::class, 1);
});

it('records the failure from the webhook', function (): void {
    config(['services.observability.token' => 'test-token']);
    $edit = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);

    $this->postJson('/api/webhook/video-cut-edit', [
        'edit_uuid' => $edit->uuid,
        'status' => 'failed',
        'error' => 'ffmpeg explodiu',
    ], ['X-Observability-Token' => 'test-token'])->assertOk();

    expect($edit->fresh()?->render_status)->toBe(VideoCutStatusEnum::Failed)
        ->and($edit->fresh()?->render_error)->toBe('ffmpeg explodiu')
        ->and(YoutubeShort::query()->where('youtube_id', 'reframe-'.$edit->uuid)->exists())->toBeFalse();
});

it('refuses the video cut edit webhook without the shared token', function (): void {
    config(['services.observability.token' => 'test-token']);
    $edit = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);

    $this->postJson('/api/webhook/video-cut-edit', [
        'edit_uuid' => $edit->uuid,
        'status' => 'done',
    ])->assertUnauthorized();

    expect($edit->fresh()?->render_status)->toBe(VideoCutStatusEnum::Generating);
});
