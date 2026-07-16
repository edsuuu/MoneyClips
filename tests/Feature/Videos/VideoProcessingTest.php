<?php

declare(strict_types=1);

use App\Jobs\FetchTemplateOutputJob;
use App\Jobs\RunReencodeJob;
use App\Jobs\StartTemplateRenderJob;
use App\Models\ProcessingJob;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use App\Services\Processing\AutoCaptionClient;
use App\Services\Processing\ReencodeClient;
use App\Services\Processing\TemplateRenderOptions;
use App\Services\Processing\TemplateStyle;
use App\Services\Processing\VideoProcessingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('creates a processing job and queues the reencode', function (): void {
    Queue::fake();
    $short = YoutubeShort::factory()->create();

    $job = resolve(VideoProcessingService::class)->startReencode($short, markReady: true);

    expect($job->type)->toBe(ProcessingJob::TYPE_REENCODE)
        ->and($job->status)->toBe('queued')
        ->and($job->options)->toBe(['mark_ready' => true]);
    Queue::assertPushed(RunReencodeJob::class, fn (RunReencodeJob $j): bool => $j->processingJobId === $job->id);
});

it('blocks a second processing while one is pending', function (): void {
    Queue::fake();
    $short = YoutubeShort::factory()->create();
    $service = resolve(VideoProcessingService::class);

    $service->startReencode($short);
    $service->startTemplateRender($short, new TemplateRenderOptions(TemplateStyle::Black, 'Canal', '@canal'));
})->throws(RuntimeException::class, 'processamento em andamento');

it('runs the reencode and stores the HQ output back in storage', function (): void {
    Storage::fake('s3');
    Http::fake(['*/reencode' => Http::response('binary-video', 200, ['Content-Type' => 'video/mp4', 'X-Reencode' => 'completed'])]);

    $short = YoutubeShort::factory()->create(['video_path' => 'shorts/abc/short_abc.mp4']);
    Storage::disk('s3')->put($short->video_path, 'original');
    $job = ProcessingJob::factory()->create(['youtube_short_id' => $short->id, 'options' => ['mark_ready' => true]]);

    new RunReencodeJob($job->id)->handle(resolve(ReencodeClient::class));

    $job->refresh();
    $short->refresh();
    expect($job->status)->toBe('completed')
        ->and($job->output_path)->toBe('shorts/abc/short_abc_HQ.mp4')
        ->and($short->processed_video_path)->toBe('shorts/abc/short_abc_HQ.mp4')
        ->and($short->ready_at)->not->toBeNull();
    Storage::disk('s3')->assertExists('shorts/abc/short_abc_HQ.mp4');
});

it('completes without output when the reencode is skipped', function (): void {
    Storage::fake('s3');
    Http::fake(['*/reencode' => Http::response(['status' => 'skipped', 'reencoded' => false])]);

    $short = YoutubeShort::factory()->create();
    Storage::disk('s3')->put($short->video_path, 'original');
    $job = ProcessingJob::factory()->create(['youtube_short_id' => $short->id]);

    new RunReencodeJob($job->id)->handle(resolve(ReencodeClient::class));

    expect($job->refresh()->status)->toBe('completed')
        ->and($job->output_path)->toBeNull()
        ->and($short->refresh()->processed_video_path)->toBeNull();
});

it('starts a template render and stores the remote id', function (): void {
    Storage::fake('s3');
    Http::fake(['*/videos' => Http::response(['uuid' => 'remote-123', 'status' => 'processing'], 202)]);

    $short = YoutubeShort::factory()->create();
    Storage::disk('s3')->put($short->video_path, 'original');
    $job = ProcessingJob::factory()->template()->create([
        'youtube_short_id' => $short->id,
        'options' => new TemplateRenderOptions(TemplateStyle::Black, 'Canal', '@canal')->toArray(),
    ]);

    new StartTemplateRenderJob($job->id)->handle(resolve(AutoCaptionClient::class));

    expect($job->refresh()->remote_id)->toBe('remote-123')
        ->and($job->status)->toBe('processing');

    $multipartField = fn ($request, string $name): ?string => collect($request->data())
        ->first(fn (array $part): bool => ($part['name'] ?? '') === $name)['contents'] ?? null;

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/videos')
        && $multipartField($request, 'variants') === 'template_black'
        && $multipartField($request, 'caption_position') === 'below'
        && $multipartField($request, 'channel_name') === 'Canal');
});

it('handles the autocaption webhook: done fetches, failed records', function (): void {
    Queue::fake();

    $done = ProcessingJob::factory()->template()->processing()->create(['remote_id' => 'remote-done']);
    $failed = ProcessingJob::factory()->template()->processing()->create(['remote_id' => 'remote-failed']);

    $this->postJson(route('autocaption.webhook'), ['uuid' => 'remote-done', 'status' => 'done'])
        ->assertOk()
        ->assertJson(['status' => 'fetch-queued']);
    Queue::assertPushed(FetchTemplateOutputJob::class, fn (FetchTemplateOutputJob $j): bool => $j->processingJobId === $done->id);

    $this->postJson(route('autocaption.webhook'), ['uuid' => 'remote-failed', 'status' => 'failed', 'error' => 'CUDA out of memory'])
        ->assertOk();
    expect($failed->refresh()->status)->toBe('failed')
        ->and($failed->error)->toBe('CUDA out of memory');

    $this->postJson(route('autocaption.webhook'), ['uuid' => 'ghost', 'status' => 'done'])->assertNotFound();

    // Retry pós-desfecho é idempotente.
    $this->postJson(route('autocaption.webhook'), ['uuid' => 'remote-failed', 'status' => 'failed'])
        ->assertOk()
        ->assertJson(['status' => 'already-finished']);
});

it('fetches the rendered template into storage and marks the short', function (): void {
    Storage::fake('s3');
    Http::fake(['*/videos/remote-123/output/*' => Http::response('rendered-bytes', 200, ['Content-Type' => 'video/mp4'])]);

    $short = YoutubeShort::factory()->create(['video_path' => 'shorts/abc/short_abc.mp4']);
    $job = ProcessingJob::factory()->template()->processing()->create([
        'youtube_short_id' => $short->id,
        'remote_id' => 'remote-123',
        'options' => new TemplateRenderOptions(TemplateStyle::White, 'Canal', '@canal')->toArray(),
    ]);

    new FetchTemplateOutputJob($job->id)->handle(resolve(AutoCaptionClient::class), resolve(DiscordNotifier::class));

    $short->refresh();
    expect($job->refresh()->status)->toBe('completed')
        ->and($short->processed_video_path)->toBe('shorts/abc/template_white_'.$short->youtube_id.'.mp4')
        ->and($short->template_rendered_at)->not->toBeNull()
        ->and($short->ready_at)->not->toBeNull();
    Storage::disk('s3')->assertExists((string) $short->processed_video_path);
});
