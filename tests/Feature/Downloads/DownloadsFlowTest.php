<?php

declare(strict_types=1);

use App\Livewire\Downloads\Index;
use App\Livewire\Downloads\NewDownload;
use App\Models\TiktokPost;
use App\Models\User;
use App\Models\YoutubeShort;
use App\Services\DownloadYoutube\DownloadYoutubeService;
use App\Services\TiktokPost\TiktokPostService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('microservices.download_youtube.base_url', 'http://download.test');
    config()->set('microservices.download_youtube.webhook_url', 'https://laravel.test/api/download-youtube/webhook');
    config()->set('microservices.tiktok_post.base_url', 'http://tiktok.test');
    config()->set('microservices.tiktok_post.callback_url', 'https://laravel.test/api/tiktok-posts/callback');
});

test('home renders login for guests and redirects authenticated users to downloads', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Entrar na sua conta');

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('downloads.index'));
});

test('download youtube service creates jobs checks status and lists items', function (): void {
    Http::fake([
        'http://download.test/shorts/download' => Http::response(['job_id' => 'job-123', 'status' => 'accepted'], 202),
        'http://download.test/shorts/download/job-123' => Http::response([
            'job_id' => 'job-123',
            'status' => 'processing',
            'total' => 2,
            'pending' => 0,
            'processing' => 1,
            'completed' => 1,
            'failed' => 0,
        ]),
        'http://download.test/shorts/items*' => Http::response([
            'total' => 1,
            'items' => [[
                'youtube_id' => 'abc123',
                'title' => 'Video teste',
                'hashtags' => ['#shorts'],
                'storage_path' => 'shorts/abc123.mp4',
                'status' => 'completed',
                'dispatch_status' => 'pending',
            ]],
        ]),
        'http://download.test/shorts/dispatch' => Http::response([
            'sent_items' => 1,
            'dispatch_status' => 'dispatched',
            'jobs' => [
                ['job_id' => 'job-123', 'sent_items' => 1, 'dispatch_status' => 'dispatched'],
            ],
        ]),
        'http://download.test/health' => Http::response(['status' => 'ok']),
    ]);

    $service = resolve(DownloadYoutubeService::class);

    expect($service->createDownload('https://www.youtube.com/@canal'))->toBe('job-123')
        ->and($service->getJobStatus('job-123')['status'])->toBe('processing')
        ->and($service->listItems(15, 0)['items'][0]['youtube_id'])->toBe('abc123')
        ->and($service->dispatchPending(50)['sent_items'])->toBe(1)
        ->and($service->health())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://download.test/shorts/download'
        && $request['channel_url'] === 'https://www.youtube.com/@canal'
        && $request['webhook_url'] === 'https://laravel.test/api/download-youtube/webhook'
        && $request['dispatch_on_complete'] === false);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://download.test/shorts/dispatch'
        && $request['mode'] === 'batch'
        && $request['batch_size'] === 50
        && $request['webhook_url'] === 'https://laravel.test/api/download-youtube/webhook'
        && $request['delete_after_dispatch'] === true);
});

test('tiktok post service queues post and stores local ledger row', function (): void {
    Http::fake([
        'http://tiktok.test/posts' => Http::response(['job_id' => 'job-tiktok-1', 'status' => 'queued'], 202),
        'http://tiktok.test/health' => Http::response(['status' => 'ok', 'queue_size' => 1, 'dry_run' => false]),
        'http://tiktok.test/session' => Http::response(['account' => 'main', 'valid' => true]),
    ]);

    $service = resolve(TiktokPostService::class);

    expect($service->queuePost('abc123', 'Video teste', ['#shorts']))->toBe('job-tiktok-1')
        ->and($service->health())->toBeTrue()
        ->and($service->session()['valid'])->toBeTrue();

    $this->assertDatabaseHas('tiktok_posts', [
        'uuid' => 'job-tiktok-1',
        'youtube_id' => 'abc123',
        'video_key' => 'shorts/abc123.mp4',
        'title' => 'Video teste',
        'status' => 'queued',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://tiktok.test/posts'
        && $request['video_id'] === 'abc123'
        && $request['webhook_url'] === 'https://laravel.test/api/tiktok-posts/callback'
        && $request['title'] === 'Video teste'
        && $request['hashtags'] === ['#shorts']);
});

test('new download screen creates a job and renders progress', function (): void {
    Http::fake([
        'http://download.test/shorts/download' => Http::response(['job_id' => 'job-web', 'status' => 'accepted'], 202),
        'http://download.test/shorts/download/job-web' => Http::response([
            'job_id' => 'job-web',
            'status' => 'processing',
            'total' => 3,
            'pending' => 1,
            'processing' => 1,
            'completed' => 1,
            'failed' => 0,
        ]),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(NewDownload::class)
        ->set('channelUrl', 'https://www.youtube.com/@canal')
        ->call('start')
        ->assertSet('jobId', 'job-web')
        ->assertSee('Processando');
});

test('downloads page renders downloaded items with tiktok statuses', function (): void {
    YoutubeShort::factory()->create([
        'youtube_id' => 'posted123',
        'title' => 'Video postado',
        'hashtags' => ['#shorts'],
        'video_path' => 'shorts/posted123.mp4',
    ]);

    YoutubeShort::factory()->create([
        'youtube_id' => 'queued123',
        'title' => 'Video em fila',
        'hashtags' => ['#teste'],
        'video_path' => 'shorts/queued123.mp4',
    ]);

    TiktokPost::query()->create([
        'uuid' => 'posted-job',
        'youtube_id' => 'posted123',
        'video_key' => 'shorts/posted123.mp4',
        'title' => 'Video postado',
        'status' => 'completed',
        'requested_at' => now(),
        'posted_at' => now(),
    ]);

    TiktokPost::query()->create([
        'uuid' => 'queued-job',
        'youtube_id' => 'queued123',
        'video_key' => 'shorts/queued123.mp4',
        'title' => 'Video em fila',
        'status' => 'queued',
        'requested_at' => now(),
    ]);

    Http::fake([
        'http://download.test/shorts/items*' => Http::response(['total' => 3, 'items' => []]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('downloads.index'))
        ->assertOk()
        ->assertSee('Estoque e postagens')
        ->assertSee('Video postado')
        ->assertSee('Postado')
        ->assertSee('Video em fila')
        ->assertSee('Em fila');
});

test('download youtube webhook imports shorts into local stock', function (): void {
    $this->postJson(route('download-youtube.webhook'), [
        'event' => 'shorts.download.dispatched',
        'job_id' => 'job-import',
        'channel_url' => 'https://www.youtube.com/@canal',
        'items' => [
            [
                'youtube_id' => 'abc123',
                'title' => 'Video importado',
                'hashtags' => ['#shorts', '#teste'],
                'storage_path' => 'shorts/abc123.mp4',
                'storage' => [
                    'path' => 'shorts/abc123.mp4',
                    'size_bytes' => 1024,
                ],
            ],
        ],
    ])
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'imported' => 1,
            'skipped' => 0,
        ]);

    $this->assertDatabaseHas('youtube_shorts', [
        'youtube_id' => 'abc123',
        'channel_url' => 'https://www.youtube.com/@canal',
        'title' => 'Video importado',
        'video_path' => 'shorts/abc123.mp4',
    ]);
});

test('downloads page can request a microservice import batch', function (): void {
    Http::fake([
        'http://download.test/shorts/items*' => Http::response(['total' => 1, 'items' => []]),
        'http://download.test/shorts/dispatch' => Http::response([
            'sent_items' => 1,
            'dispatch_status' => 'dispatched',
            'jobs' => [
                ['job_id' => 'job-import', 'sent_items' => 1, 'dispatch_status' => 'dispatched'],
            ],
        ]),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->call('importFromMicroservice')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://download.test/shorts/dispatch'
        && $request['batch_size'] === 1000
        && $request['webhook_url'] === 'https://laravel.test/api/download-youtube/webhook'
        && $request['delete_after_dispatch'] === true);
});

test('downloads page can queue a tiktok post', function (): void {
    Http::fake([
        'http://download.test/shorts/items*' => Http::response(['total' => 0, 'items' => []]),
        'http://tiktok.test/posts' => Http::response(['job_id' => 'job-action', 'status' => 'queued'], 202),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->call('postToTiktok', 'abc123', 'Video teste', ['#shorts'])
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tiktok_posts', [
        'uuid' => 'job-action',
        'youtube_id' => 'abc123',
        'status' => 'queued',
    ]);
});

test('tiktok callback updates local post status', function (): void {
    $this->postJson(route('tiktok-posts.callback'), [
        'job_id' => 'callback-job',
        'video_id' => 'abc123',
        'status' => 'completed',
        'session_valid' => true,
        'login_failed' => false,
        'title' => 'Video callback',
        'error' => null,
        'finished_at' => now()->toISOString(),
    ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->assertDatabaseHas('tiktok_posts', [
        'uuid' => 'callback-job',
        'youtube_id' => 'abc123',
        'video_key' => 'shorts/abc123.mp4',
        'title' => 'Video callback',
        'status' => 'completed',
    ]);

    expect(TiktokPost::query()->where('uuid', 'callback-job')->value('posted_at'))->not->toBeNull();
});
