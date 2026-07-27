<?php

declare(strict_types=1);

use App\Jobs\PostSlotToPlatformJob;
use App\Livewire\Videos\Index;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('splits the stock into downloaded and ready sections', function (): void {
    $downloaded = YoutubeShort::factory()->create(['ready_at' => null]);
    $ready = YoutubeShort::factory()->ready()->create();
    YoutubeShort::factory()->posted()->create();

    Livewire::test(Index::class)
        ->assertSee($downloaded->title)
        ->assertSee($ready->title)
        ->assertViewHas('downloaded', fn (array $items): bool => count($items) === 1)
        ->assertViewHas('ready', fn (array $items): bool => count($items) === 1)
        ->assertViewHas('posted', fn (array $items): bool => count($items) === 1);
});

it('requires hashtags before marking a video as ready', function (): void {
    $short = YoutubeShort::factory()->create(['hashtags' => [], 'ready_at' => null]);

    Livewire::test(Index::class)->call('markReady', $short->id);
    expect($short->refresh()->ready_at)->toBeNull();

    $short->update(['hashtags' => ['#shorts']]);
    Livewire::test(Index::class)->call('markReady', $short->id);
    expect($short->refresh()->ready_at)->not->toBeNull();
});

it('saves title and hashtags from the review modal', function (): void {
    $short = YoutubeShort::factory()->create();

    Livewire::test(Index::class)
        ->call('openEdit', $short->id)
        ->set('editTitle', 'Novo título')
        ->set('editHashtags', 'shorts, #podcast')
        ->call('saveEdit');

    $short->refresh();
    expect($short->title)->toBe('Novo título')
        ->and($short->hashtags)->toBe(['#shorts', '#podcast']);
});

it('queues instant posts for implemented platforms without active posts', function (): void {
    Queue::fake();
    $short = YoutubeShort::factory()->ready()->create();

    Livewire::test(Index::class)
        ->call('openInstant', $short->id)
        ->call('confirmInstant');

    Queue::assertPushed(PostSlotToPlatformJob::class, 2);
    Queue::assertPushed(fn (PostSlotToPlatformJob $job): bool => $job->slotId === null && $job->shortId === $short->id && $job->platform === 'youtube');
});

it('assigns a templated video to an empty slot', function (): void {
    $short = YoutubeShort::factory()->create(['template_rendered_at' => now(), 'ready_at' => null]);
    $slot = ScheduleSlot::factory()->create(['slot_date' => now()->addDays(2)->toDateString(), 'slot_time' => '12:00:00']);

    Livewire::test(Index::class)
        ->call('openSchedule', $short->id)
        ->call('assignToSlot', $slot->id);

    expect($slot->refresh()->youtube_short_id)->toBe($short->id)
        ->and($short->refresh()->ready_at)->not->toBeNull();
});
