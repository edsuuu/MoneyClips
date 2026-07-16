<?php

declare(strict_types=1);

use App\Jobs\PostSlotToPlatformJob;
use App\Jobs\ReencodeAndPostSlotJob;
use App\Livewire\Schedule\Index;
use App\Models\AppSetting;
use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use App\Services\AutoPost\AutoPostDispatcherService;
use App\Services\Reencode\ReencodeShortService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00'));
    Queue::fake();
});

function dueEmptySlot(array $attributes = []): ScheduleSlot
{
    return ScheduleSlot::factory()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '11:58:00',
        'youtube_short_id' => null,
        ...$attributes,
    ]);
}

it('assigns and claims the slot in the same tick, so the next tick cannot steal it', function (): void {
    AppSetting::set(AppSetting::RANDOM_MODE, true);
    $slot = dueEmptySlot();
    $short = YoutubeShort::factory()->ready()->create();

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    // Atribuição + claim na mesma UPDATE: o scope `due` do tick seguinte não
    // pode pegar o slot e postar o original antes do reencode.
    expect($slot->refresh()->youtube_short_id)->toBe($short->id)
        ->and($slot->dispatched_at)->not->toBeNull();
    Queue::assertPushed(fn (ReencodeAndPostSlotJob $job): bool => $job->slotId === $slot->id);

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    Queue::assertPushed(ReencodeAndPostSlotJob::class, 1);
    Queue::assertNotPushed(PostSlotToPlatformJob::class);
});

it('leaves empty slots alone when the flag is off', function (): void {
    dueEmptySlot();
    YoutubeShort::factory()->ready()->create();

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    Queue::assertNothingPushed();
    expect(ScheduleSlot::query()->whereNotNull('youtube_short_id')->count())->toBe(0);
});

it('skips gracefully when the ready stock is empty', function (): void {
    AppSetting::set(AppSetting::RANDOM_MODE, true);
    $slot = dueEmptySlot();

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    Queue::assertNothingPushed();
    expect($slot->refresh()->youtube_short_id)->toBeNull();
});

it('never picks a video that already has an active social post', function (): void {
    AppSetting::set(AppSetting::RANDOM_MODE, true);
    $slot = dueEmptySlot();

    // Restrito pela moderação do TikTok: não volta pro sorteio.
    $restricted = YoutubeShort::factory()->ready()->create();
    SocialPost::factory()->create([
        'platform' => 'tiktok',
        'youtube_id' => $restricted->youtube_id,
        'status' => 'restricted',
    ]);

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    Queue::assertNothingPushed();
    expect($slot->refresh()->youtube_short_id)->toBeNull();
});

it('does not hand the same video to two empty slots in one tick', function (): void {
    AppSetting::set(AppSetting::RANDOM_MODE, true);
    dueEmptySlot(['slot_time' => '11:57:00']);
    dueEmptySlot(['slot_time' => '11:58:00']);
    YoutubeShort::factory()->ready()->create();

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    // 1 vídeo pronto → só 1 slot preenchido; o outro fica vazio (sem estoque).
    Queue::assertPushed(ReencodeAndPostSlotJob::class, 1);
    expect(ScheduleSlot::query()->whereNotNull('youtube_short_id')->count())->toBe(1);
});

it('toggles the flag from the schedule screen', function (): void {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(Index::class);

    $component->call('toggleRandomMode')->assertSet('view', 'week');
    expect((bool) AppSetting::query()->where('key', AppSetting::RANDOM_MODE)->value('enabled'))->toBeTrue();

    $component->call('toggleRandomMode');
    expect((bool) AppSetting::query()->where('key', AppSetting::RANDOM_MODE)->value('enabled'))->toBeFalse();
});

it('reencodes the video and then fans out the normal posting jobs', function (): void {
    Storage::fake('s3');
    Http::fake(['*/reencode' => Http::response('hq-bytes', 200, ['X-Reencode' => 'completed'])]);

    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');
    $slot = ScheduleSlot::factory()->dispatched()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '11:58:00',
        'youtube_short_id' => $short->id,
    ]);

    new ReencodeAndPostSlotJob($slot->id)
        ->handle(resolve(ReencodeShortService::class), resolve(AutoPostDispatcherService::class));

    $short->refresh();
    expect($short->processed_video_path)->not->toBeNull()
        ->and(Storage::disk('s3')->exists((string) $short->processed_video_path))->toBeTrue();
    Queue::assertPushed(fn (PostSlotToPlatformJob $job): bool => $job->slotId === $slot->id && $job->platform === 'youtube');
});

it('still posts the original video when the reencode fails', function (): void {
    Storage::fake('s3');
    Http::fake(['*/reencode' => Http::response('boom', 500)]);

    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');
    $slot = ScheduleSlot::factory()->dispatched()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '11:58:00',
        'youtube_short_id' => $short->id,
    ]);

    new ReencodeAndPostSlotJob($slot->id)
        ->handle(resolve(ReencodeShortService::class), resolve(AutoPostDispatcherService::class));

    expect($short->refresh()->processed_video_path)->toBeNull();
    Queue::assertPushed(PostSlotToPlatformJob::class);
});
