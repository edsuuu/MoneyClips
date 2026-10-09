<?php

declare(strict_types=1);

use App\Enums\PostStatusEnum;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\Posting\PostSchedulerService;
use Illuminate\Support\Facades\Date;

function takeSlot(SocialAccount $account, string $at, PostStatusEnum $status = PostStatusEnum::Scheduled): void
{
    SocialPost::query()->create([
        'youtube_short_id' => YoutubeShort::factory()->create()->id,
        'social_account_id' => $account->id,
        'scheduled_for' => $at,
        'status' => $status,
    ]);
}

function nextSlotFor(SocialAccount $account): string
{
    return resolve(PostSchedulerService::class)->nextSlot($account)->format('Y-m-d H:i');
}

beforeEach(function (): void {
    Date::setTestNow('2026-10-08 10:30:00');
    config([
        'posting.times' => ['08:00', '10:00', '14:00', '17:00', '20:00', '21:30'],
        'posting.per_day' => 6,
        'posting.min_gap_minutes' => 60,
    ]);

    $this->account = SocialAccount::query()->create(['platform' => 'tiktok', 'name' => '@conta']);
});

it('picks the next time of the day that has not passed yet', function (): void {
    expect(nextSlotFor($this->account))->toBe('2026-10-08 14:00');
});

it('skips a time that starts in less than 5 minutes', function (): void {
    Date::setTestNow('2026-10-08 13:56:00');

    expect(nextSlotFor($this->account))->toBe('2026-10-08 17:00');
});

it('rolls over to the first time of tomorrow after the last one of the day', function (): void {
    Date::setTestNow('2026-10-08 21:30:00');

    expect(nextSlotFor($this->account))->toBe('2026-10-09 08:00');
});

it('skips a taken time and keeps one hour away from a post outside the grid', function (): void {
    takeSlot($this->account, '2026-10-08 14:00');
    takeSlot($this->account, '2026-10-08 16:30');

    expect(nextSlotFor($this->account))->toBe('2026-10-08 20:00');
});

it('moves to the next day once the day hits per_day', function (): void {
    config(['posting.per_day' => 2]);
    takeSlot($this->account, '2026-10-08 08:00', PostStatusEnum::Published);
    takeSlot($this->account, '2026-10-08 11:15', PostStatusEnum::Posting);

    expect(nextSlotFor($this->account))->toBe('2026-10-09 08:00');
});

it('ignores canceled, failed and missed posts and posts of another account', function (): void {
    takeSlot($this->account, '2026-10-08 14:00', PostStatusEnum::Canceled);
    takeSlot($this->account, '2026-10-08 14:00', PostStatusEnum::Failed);
    takeSlot($this->account, '2026-10-08 14:00', PostStatusEnum::Missed);
    takeSlot(SocialAccount::query()->create(['platform' => 'youtube', 'name' => 'Canal']), '2026-10-08 14:00');

    expect(nextSlotFor($this->account))->toBe('2026-10-08 14:00');
});

it('accepts env times out of order and without a leading zero', function (): void {
    Date::setTestNow('2026-10-08 07:00:00');
    config(['posting.times' => ['20:00', '8:00', '14:00']]);

    expect(nextSlotFor($this->account))->toBe('2026-10-08 08:00');
});
