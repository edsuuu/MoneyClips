<?php

declare(strict_types=1);

use App\Jobs\PostYoutubeShortJob;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Queue;

test('dispatches one job for a random available short by default', function (): void {
    Queue::fake();

    YoutubeShort::factory()->count(3)->create();

    $this->artisan('youtube:dispatch-posts')
        ->assertExitCode(0);

    Queue::assertPushed(PostYoutubeShortJob::class, 1);
});

test('count option dispatches multiple jobs without repeating shorts', function (): void {
    Queue::fake();

    YoutubeShort::factory()->count(5)->create();

    $this->artisan('youtube:dispatch-posts', ['--count' => 3])
        ->assertExitCode(0);

    Queue::assertPushed(PostYoutubeShortJob::class, 3);

    $ids = [];
    Queue::assertPushed(PostYoutubeShortJob::class, function (PostYoutubeShortJob $job) use (&$ids): bool {
        $ids[] = $job->youtubeShortId;

        return true;
    });

    expect($ids)->toHaveCount(3)
        ->and(array_unique($ids))->toHaveCount(3);
});

test('ignores shorts already posted or not downloaded', function (): void {
    Queue::fake();

    YoutubeShort::factory()->posted()->create();
    YoutubeShort::factory()->notDownloaded()->create();

    $this->artisan('youtube:dispatch-posts')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('count must be at least one', function (): void {
    Queue::fake();

    $this->artisan('youtube:dispatch-posts', ['--count' => 0])
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});
