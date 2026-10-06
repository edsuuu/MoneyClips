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
use App\Services\Video\VideoCutEditRenderService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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

function editSpec(array $changes = []): array
{
    return [
        'version' => 1,
        'caption_preset' => 'verde',
        'cuts' => [[0.1, 0.62]],
        'captions' => [['t' => [0.58, 1.3], 'text' => 'qual a comida', 'style' => 'speech', 'pos' => 'bottom']],
        'punches' => [['t' => [11.2, 11.8], 'kind' => 'punch']],
        'title' => 'ele meteu o MICHAEL JACKSON pra trás 😂😂😂 @podpah',
        'hashtags' => ['#cortes', '#podpah', '#bateouregaca', '#cocielo'],
        ...$changes,
    ];
}

it('sends the spec to /reframe as literal captions with empty overlays and sfx', function (): void {
    config(['services.video_cut_edit.watermark' => '@unkvoid_clips']);
    Http::fake(['*/reframe' => Http::response(['uuid' => 'job'], 202)]);
    $edit = makeEditForRender($this->user);
    $edit->update(['spec' => editSpec()]);

    resolve(VideoCutEditRenderService::class)->startRender($edit->fresh(), null);

    Http::assertSent(fn (Request $request): bool => $request['cuts'] === editSpec()['cuts']
        && $request['captions'] === editSpec()['captions']
        && $request['caption_preset'] === 'verde'
        && $request['watermark'] === '@unkvoid_clips'
        && $request['overlays'] === []
        && $request['sfx'] === []);
});

it('sends no literal caption field without a spec', function (): void {
    Http::fake(['*/reframe' => Http::response(['uuid' => 'job'], 202)]);
    $edit = makeEditForRender($this->user);

    resolve(VideoCutEditRenderService::class)->startRender($edit, null);

    Http::assertSent(fn (Request $request): bool => array_intersect_key($request->data(), array_flip(['cuts', 'captions', 'caption_preset', 'watermark', 'overlays', 'sfx'])) === []);
});

it('stocks the short with the spec title and keeps an edited title on re-render', function (): void {
    config(['services.observability.token' => 'test-token']);
    $edit = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);
    $edit->update(['spec' => editSpec()]);

    $this->postJson('/api/webhook/video-cut-edit', ['edit_uuid' => $edit->uuid, 'status' => 'done'], ['X-Observability-Token' => 'test-token'])->assertOk();

    $short = YoutubeShort::query()->where('youtube_id', 'reframe-'.$edit->uuid)->firstOrFail();

    expect($short->title)->toBe(editSpec()['title'])
        ->and($short->hashtags)->toBe(editSpec()['hashtags']);

    $short->update(['title' => 'título do dono', 'hashtags' => ['#dono']]);
    $edit->update(['render_status' => VideoCutStatusEnum::Generating, 'spec' => editSpec(['title' => 'outro título'])]);

    $this->postJson('/api/webhook/video-cut-edit', ['edit_uuid' => $edit->uuid, 'status' => 'done'], ['X-Observability-Token' => 'test-token'])->assertOk();

    expect($short->fresh()?->title)->toBe('título do dono')
        ->and($short->fresh()?->hashtags)->toBe(['#dono'])
        ->and(YoutubeShort::query()->where('youtube_id', 'reframe-'.$edit->uuid)->count())->toBe(1);
});

it('composes the punches at render time and reapplies them after the framing is adjusted', function (): void {
    Http::fake(['*/reframe' => Http::response(['uuid' => 'job'], 202)]);
    $edit = makeEditForRender($this->user);
    $edit->update(['spec' => editSpec()]);

    $punchKeyframe = fn (Request $request): array => collect($request['keyframes'])->firstWhere('t', 11.2)['regions'][0];

    resolve(VideoCutEditRenderService::class)->startRender($edit->fresh(), null);

    $edit->update(['keyframes' => [['t' => 0.0, 'mode' => 'vertical', 'regions' => [['x' => 0.6, 'y' => 0.0, 'w' => 0.3164, 'h' => 1.0]]]]]);
    resolve(VideoCutEditRenderService::class)->startRender($edit->fresh(), null);

    $sent = Http::recorded()->map(fn (array $pair): array => $punchKeyframe($pair[0]))->all();

    expect($sent[0])->toBe(['x' => 0.2703, 'y' => 0.2389, 'w' => 0.1758, 'h' => 0.5556])
        ->and($sent[1]['x'])->toBe(0.6703)
        ->and($sent[1]['h'])->toBe(0.5556)
        ->and($edit->fresh()?->keyframes)->toHaveCount(1);
});

it('falls back to the cut title and hashtags, then to the video name, when the spec has none', function (): void {
    config(['services.observability.token' => 'test-token']);
    $edit = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);
    $edit->videoCut->update(['title' => 'título do corte', 'hashtags' => ['#corte']]);

    $this->postJson('/api/webhook/video-cut-edit', ['edit_uuid' => $edit->uuid, 'status' => 'done'], ['X-Observability-Token' => 'test-token'])->assertOk();

    $short = YoutubeShort::query()->where('youtube_id', 'reframe-'.$edit->uuid)->firstOrFail();

    expect($short->title)->toBe('título do corte')
        ->and($short->hashtags)->toBe(['#corte']);

    $other = makeEditForRender($this->user, VideoCutStatusEnum::Generating->value);

    $this->postJson('/api/webhook/video-cut-edit', ['edit_uuid' => $other->uuid, 'status' => 'done'], ['X-Observability-Token' => 'test-token'])->assertOk();

    expect(YoutubeShort::query()->where('youtube_id', 'reframe-'.$other->uuid)->firstOrFail()->title)->toBe($other->videoCut->video->name);
});
