<?php

declare(strict_types=1);

use App\Enums\PostStatusEnum;
use App\Jobs\PublishPostJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\Posting\PostResultData;
use App\Services\TikTokUploader\TikTokUploaderPostService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakePostService;

/**
 * @param  array<string, mixed>  $attributes
 */
function duePost(SocialAccount $account, array $attributes = []): SocialPost
{
    $short = YoutubeShort::factory()->create();
    Storage::disk('s3')->put($short->video_path, 'mp4');

    return SocialPost::query()->create([
        'youtube_short_id' => $short->id,
        'social_account_id' => $account->id,
        'scheduled_for' => now(),
        'status' => PostStatusEnum::Scheduled,
        ...$attributes,
    ]);
}

function fakeProvider(PostResultData|Throwable $outcome, ?Closure $whileUploading = null): FakePostService
{
    $fake = new FakePostService($outcome, $whileUploading);
    app()->instance(TikTokUploaderPostService::class, $fake);

    return $fake;
}

beforeEach(function (): void {
    Date::setTestNow('2026-10-08 20:00:00');
    Storage::fake('s3');

    $this->account = SocialAccount::query()->create(['platform' => 'tiktok', 'name' => '@conta']);
});

it('claims the post that is due and dispatches it on the posting queue', function (): void {
    Queue::fake();
    $due = duePost($this->account);
    $later = duePost($this->account, ['scheduled_for' => now()->addMinutes(90)]);

    $this->artisan('posts:dispatch')->assertSuccessful();

    $due->refresh();
    expect($due->status)->toBe(PostStatusEnum::Posting)
        ->and($due->attempts)->toBe(1)
        ->and($due->started_at)->not->toBeNull()
        ->and($later->refresh()->status)->toBe(PostStatusEnum::Scheduled);

    Queue::assertPushedOn('posting', PublishPostJob::class, fn (PublishPostJob $job): bool => $job->postId === $due->id);
    Queue::assertPushed(PublishPostJob::class, 1);
});

it('does not dispatch a post another worker claimed between the read and the UPDATE', function (): void {
    Queue::fake();
    $post = duePost($this->account);

    SocialPost::retrieved(function (SocialPost $loaded): void {
        SocialPost::query()->whereKey($loaded->id)->update(['status' => PostStatusEnum::Posting, 'attempts' => 1]);
    });

    $this->artisan('posts:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($post->refresh()->attempts)->toBe(1);
});

it('dispatches once when the dispatcher runs twice', function (): void {
    Queue::fake();
    $post = duePost($this->account);

    $this->artisan('posts:dispatch')->assertSuccessful();
    $this->artisan('posts:dispatch')->assertSuccessful();

    Queue::assertPushed(PublishPostJob::class, 1);
    expect($post->refresh()->attempts)->toBe(1);
});

it('turns a post past the grace window into Missed and never posts it late', function (): void {
    Queue::fake();
    $missed = duePost($this->account, ['scheduled_for' => now()->subMinutes(31)]);
    $late = duePost($this->account, ['scheduled_for' => now()->subMinutes(29)]);

    $this->artisan('posts:dispatch')->assertSuccessful();

    expect($missed->refresh()->status)->toBe(PostStatusEnum::Missed)
        ->and($late->refresh()->status)->toBe(PostStatusEnum::Posting);
    Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->postId === $late->id);
    Queue::assertPushed(PublishPostJob::class, 1);
});

it('fails a post stuck in Posting as unknown outcome and never queues it again', function (): void {
    Queue::fake();
    $stuck = duePost($this->account, ['status' => PostStatusEnum::Posting, 'started_at' => now()->subMinutes(61), 'attempts' => 1]);
    $alive = duePost($this->account, ['status' => PostStatusEnum::Posting, 'started_at' => now()->subMinutes(10), 'attempts' => 1]);

    $this->artisan('posts:dispatch')->assertSuccessful();
    $this->artisan('posts:dispatch')->assertSuccessful();

    expect($stuck->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($stuck->error)->toContain('Resultado desconhecido')
        ->and($stuck->attempts)->toBe(1)
        ->and($alive->refresh()->status)->toBe(PostStatusEnum::Posting);
    Queue::assertNothingPushed();
});

it('fails right away with the reason when the account is inactive or its session is invalid', function (): void {
    Queue::fake();
    $inactive = duePost(SocialAccount::query()->create(['platform' => 'tiktok', 'name' => '@off', 'is_active' => false]));
    $invalid = duePost(SocialAccount::query()->create(['platform' => 'tiktok', 'name' => '@expirada', 'session_status' => SocialAccount::SESSION_INVALID]));

    $this->artisan('posts:dispatch')->assertSuccessful();

    expect($inactive->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($inactive->error)->toContain('desativada')
        ->and($invalid->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($invalid->error)->toContain('reconecte');
    Queue::assertNothingPushed();
});

it('publishes with link, privacy and posted_tiktok_at and deletes the temp file', function (): void {
    $fake = fakeProvider(PostResultData::published('https://www.tiktok.com/@conta/video/1', 'public'));
    $post = duePost($this->account);

    $this->artisan('posts:dispatch')->assertSuccessful();

    $post->refresh();
    expect($post->status)->toBe(PostStatusEnum::Published)
        ->and($post->url)->toBe('https://www.tiktok.com/@conta/video/1')
        ->and($post->privacy)->toBe('public')
        ->and($post->posted_at)->not->toBeNull()
        ->and($post->youtubeShort->posted_tiktok_at)->not->toBeNull()
        ->and($post->youtubeShort->posted_youtube_at)->toBeNull()
        ->and($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['file_existed'])->toBeTrue()
        ->and(is_file($fake->calls[0]['local_path']))->toBeFalse();
});

it('keeps an async post in Posting with the external_id for the webhook', function (): void {
    fakeProvider(PostResultData::pending('job-123'));
    $post = duePost($this->account);

    $this->artisan('posts:dispatch')->assertSuccessful();

    expect($post->refresh()->status)->toBe(PostStatusEnum::Posting)
        ->and($post->external_id)->toBe('job-123');
});

it('fails with the reason the provider gave', function (): void {
    fakeProvider(PostResultData::failed('Conta restrita pela plataforma.'));
    $post = duePost($this->account);

    $this->artisan('posts:dispatch')->assertSuccessful();

    expect($post->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($post->error)->toBe('Conta restrita pela plataforma.');
});

it('fails without calling the provider when the video is missing from MinIO', function (): void {
    $fake = fakeProvider(PostResultData::published(null, 'public'));
    $post = duePost($this->account);
    Storage::disk('s3')->delete($post->youtubeShort->video_path);

    $this->artisan('posts:dispatch')->assertSuccessful();

    expect($post->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($post->error)->toContain('Vídeo não encontrado')
        ->and($fake->calls)->toBeEmpty();
});

it('never retries blindly: an exception mid-upload fails the post and the dispatcher does not repost it', function (): void {
    fakeProvider(new RuntimeException('Conexão caiu no meio do upload.'));
    $post = duePost($this->account);

    expect(fn (): int => Artisan::call('posts:dispatch'))->toThrow(RuntimeException::class, 'Conexão caiu');

    expect($post->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($post->error)->toContain('Conexão caiu no meio do upload.')
        ->and($post->error)->toContain('confira na plataforma')
        ->and($post->attempts)->toBe(1)
        ->and((new PublishPostJob($post->id))->tries)->toBe(1);

    Queue::fake();
    $this->artisan('posts:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($post->refresh()->status)->toBe(PostStatusEnum::Failed);
});

it('skips a post that left Posting before the job ran', function (): void {
    $fake = fakeProvider(PostResultData::published(null, 'public'));
    $post = duePost($this->account, ['status' => PostStatusEnum::Canceled]);

    dispatch_sync(new PublishPostJob($post->id));

    expect($fake->calls)->toBeEmpty()
        ->and($post->refresh()->status)->toBe(PostStatusEnum::Canceled);
});

it('does not overwrite a post the reaper already failed when the upload finishes late', function (PostResultData $late): void {
    $reaperError = 'Resultado desconhecido: a postagem ficou sem resposta. Confira na plataforma antes de tentar de novo.';
    fakeProvider($late, function (SocialPost $post) use ($reaperError): void {
        SocialPost::query()->whereKey($post->id)->update(['status' => PostStatusEnum::Failed, 'error' => $reaperError]);
    });
    $post = duePost($this->account);

    $this->artisan('posts:dispatch')->assertSuccessful();

    $post->refresh();
    expect($post->status)->toBe(PostStatusEnum::Failed)
        ->and($post->error)->toBe($reaperError)
        ->and($post->url)->toBeNull()
        ->and($post->external_id)->toBeNull()
        ->and($post->youtubeShort->posted_tiktok_at)->toBeNull();
})->with([
    'published' => fn (): PostResultData => PostResultData::published('https://www.tiktok.com/@conta/video/1', 'public'),
    'pending' => fn (): PostResultData => PostResultData::pending('job-123'),
]);
