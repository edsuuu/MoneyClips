<?php

declare(strict_types=1);

use App\Jobs\PostSlotToPlatform;
use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\AutoPost\PosterRegistry;
use App\Services\AutoPost\Posters\PosterContract;
use App\Services\AutoPost\Posters\PosterResult;
use App\Services\AutoPost\Posters\PostTask;
use App\Services\DiscordNotifier;
use Illuminate\Support\Facades\Storage;

function fakePoster(string $platform, PosterResult $result): PosterContract
{
    return new readonly class($platform, $result) implements PosterContract
    {
        public function __construct(private string $name, private PosterResult $result) {}

        public function platform(): string
        {
            return $this->name;
        }

        public function isEnabled(): bool
        {
            return true;
        }

        public function post(PostTask $task): PosterResult
        {
            return $this->result;
        }
    };
}

function runJob(ScheduleSlot $slot, PosterContract $poster): void
{
    new PostSlotToPlatform($slot->id, $poster->platform())
        ->handle(new PosterRegistry([$poster]), resolve(DiscordNotifier::class));
}

it('persists the ledger and marks the short on success', function (): void {
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');
    $slot = ScheduleSlot::factory()->dispatched()->create(['youtube_short_id' => $short->id]);

    runJob($slot, fakePoster('youtube', PosterResult::ok('youtube', 'https://youtube.com/shorts/x')));

    $ledger = SocialPost::query()->where('schedule_slot_id', $slot->id)->where('platform', 'youtube')->sole();
    expect($ledger->status)->toBe('completed')
        ->and($ledger->posted_at)->not->toBeNull()
        ->and($ledger->video_key)->toBe($short->video_path)
        ->and($short->refresh()->posted_youtube_at)->not->toBeNull();
});

it('fails fast with a clear error when the file is missing from storage', function (): void {
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    $slot = ScheduleSlot::factory()->dispatched()->create(['youtube_short_id' => $short->id]);

    runJob($slot, fakePoster('youtube', PosterResult::ok('youtube')));

    $ledger = SocialPost::query()->where('schedule_slot_id', $slot->id)->sole();
    expect($ledger->status)->toBe('failed')
        ->and($ledger->error)->toContain('não encontrado no MinIO')
        ->and($short->refresh()->posted_youtube_at)->toBeNull();
});

it('records restricted results without marking the short as posted', function (): void {
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');
    $slot = ScheduleSlot::factory()->dispatched()->create(['youtube_short_id' => $short->id]);

    runJob($slot, fakePoster('tiktok', PosterResult::restricted('tiktok', 'Conteúdo restrito')));

    $ledger = SocialPost::query()->where('schedule_slot_id', $slot->id)->sole();
    expect($ledger->status)->toBe('restricted')
        ->and($short->refresh()->posted_tiktok_at)->toBeNull();
});

it('is idempotent per (slot, platform) pair', function (): void {
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');
    $slot = ScheduleSlot::factory()->dispatched()->create(['youtube_short_id' => $short->id]);

    runJob($slot, fakePoster('youtube', PosterResult::failed('youtube', 'boom')));
    runJob($slot, fakePoster('youtube', PosterResult::ok('youtube')));

    expect(SocialPost::query()->where('schedule_slot_id', $slot->id)->count())->toBe(1)
        ->and(SocialPost::query()->where('schedule_slot_id', $slot->id)->sole()->status)->toBe('completed');
});
