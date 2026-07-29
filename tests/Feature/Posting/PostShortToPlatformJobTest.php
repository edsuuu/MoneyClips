<?php

declare(strict_types=1);

use App\Jobs\PostShortToPlatformJob;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\AutoPost\PosterInterface;
use App\Services\AutoPost\PosterRegistryService;
use App\Services\AutoPost\PosterResultData;
use App\Services\AutoPost\PostTaskData;
use Illuminate\Support\Facades\Storage;

function fakePoster(string $platform, PosterResultData $result): PosterInterface
{
    return new readonly class($platform, $result) implements PosterInterface
    {
        public function __construct(private string $name, private PosterResultData $result) {}

        public function platform(): string
        {
            return $this->name;
        }

        public function post(PostTaskData $task): PosterResultData
        {
            return $this->result;
        }
    };
}

function runJob(YoutubeShort $short, PosterInterface $poster): void
{
    new PostShortToPlatformJob($poster->platform(), $short->id)
        ->handle(new PosterRegistryService([$poster]), resolve(DiscordNotifierService::class));
}

it('persists the ledger and marks the short on success', function (): void {
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');

    runJob($short, fakePoster('youtube', PosterResultData::ok('youtube', 'https://youtube.com/shorts/x')));

    $ledger = SocialPost::query()->where('youtube_id', $short->youtube_id)->where('platform', 'youtube')->sole();
    expect($ledger->status)->toBe('completed')
        ->and($ledger->posted_at)->not->toBeNull()
        ->and($ledger->video_key)->toBe($short->video_path)
        ->and($short->refresh()->posted_youtube_at)->not->toBeNull();
});

it('fails fast with a clear error when the file is missing from storage', function (): void {
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();

    runJob($short, fakePoster('youtube', PosterResultData::ok('youtube')));

    $ledger = SocialPost::query()->where('youtube_id', $short->youtube_id)->sole();
    expect($ledger->status)->toBe('failed')
        ->and($ledger->error)->toContain('não encontrado no MinIO')
        ->and($short->refresh()->posted_youtube_at)->toBeNull();
});

it('records restricted results without marking the short as posted', function (): void {
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');

    runJob($short, fakePoster('tiktok', PosterResultData::restricted('tiktok', 'Conteúdo restrito')));

    $ledger = SocialPost::query()->where('youtube_id', $short->youtube_id)->sole();
    expect($ledger->status)->toBe('restricted')
        ->and($short->refresh()->posted_tiktok_at)->toBeNull();
});

it('stores the microservice job_id as the ledger uuid on queued results', function (): void {
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');

    runJob($short, fakePoster('tiktok', PosterResultData::queued('tiktok', 'job-abc')));

    $ledger = SocialPost::query()->where('youtube_id', $short->youtube_id)->sole();
    expect($ledger->status)->toBe('queued')
        ->and($ledger->uuid)->toBe('job-abc')
        ->and($ledger->posted_at)->toBeNull()
        ->and($short->refresh()->posted_tiktok_at)->toBeNull();
});
