<?php

declare(strict_types=1);

use App\Livewire\Videos\Editor;
use App\Models\ProcessingJob;
use App\Models\Status;
use App\Models\User;
use App\Models\Video;
use App\Services\VideoProcessor\Contracts\VideoProcessorProviderInterface;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::factory()->create();
    Auth::login($this->user);
});

function makeVideoWithCut(): Video
{
    $video = Video::query()->create([
        'url' => 'https://www.youtube.com/watch?v=abc123',
        'status_id' => Status::idFor('waiting_cuts'),
        'title' => 'Demo',
        'duration_seconds' => 120.0,
        'source_provider' => 'youtube',
        'external_video_id' => 'abc123',
    ]);

    $video->files()->create([
        'type' => 'original',
        'path' => 'videos/abc123/original.mp4',
        'disk' => 'minio',
    ]);

    $video->cuts()->create([
        'index' => 1,
        'name' => 'PT1',
        'type' => 'pt1',
        'source' => 'manual',
        'start_seconds' => 0.0,
        'end_seconds' => 60.0,
        'duration_seconds' => 60.0,
        'status_id' => Status::idFor('pending'),
    ]);

    return $video;
}

test('progress bar appears immediately after starting a render', function (): void {
    $mockProvider = mock(VideoProcessorProviderInterface::class);
    $mockProvider->shouldReceive('renderCuts')
        ->once()
        ->andReturn(['job_id' => 'render-job-xyz']);
    $this->app->instance(VideoProcessorProviderInterface::class, $mockProvider);

    $video = makeVideoWithCut();

    Livewire::actingAs($this->user)
        ->test(Editor::class, ['video' => $video])
        ->call('renderCuts')
        ->assertHasNoErrors()
        ->assertSet('renderJobId', 'render-job-xyz')
        ->assertViewHas('activeJobId', 'render-job-xyz');
});

test('a finished render releases the progress bar on refresh', function (): void {
    $mockProvider = mock(VideoProcessorProviderInterface::class);
    $mockProvider->shouldReceive('renderCuts')
        ->once()
        ->andReturn(['job_id' => 'render-job-done']);
    $this->app->instance(VideoProcessorProviderInterface::class, $mockProvider);

    $video = makeVideoWithCut();

    $component = Livewire::actingAs($this->user)
        ->test(Editor::class, ['video' => $video])
        ->call('renderCuts')
        ->assertSet('renderJobId', 'render-job-done');

    // Simula o webhook do Python concluindo o job.
    ProcessingJob::query()
        ->where('external_job_id', 'render-job-done')
        ->update(['status_id' => Status::idFor('completed')]);

    $component->call('refreshStatus')
        ->assertSet('renderJobId', null)
        ->assertViewHas('activeJobId', fn ($value): bool => $value === null);
});

test('a stale stuck job superseded by a newer completed job shows no progress bar', function (): void {
    $video = makeVideoWithCut();

    // Job antigo travado em "processing" (callback nunca chegou).
    $stale = $video->processingJobs()->create([
        'type' => 'subtitle_full',
        'provider' => 'video_processor',
        'status_id' => Status::idFor('processing'),
        'external_job_id' => 'stale-stuck-job',
        'progress' => 40,
        'stage' => 'subtitle_full',
    ]);
    $stale->forceFill(['created_at' => now()->subDays(3)])->save();

    // Job mais recente já concluído.
    $video->processingJobs()->create([
        'type' => 'render_cuts',
        'provider' => 'video_processor',
        'status_id' => Status::idFor('completed'),
        'external_job_id' => 'recent-done-job',
        'progress' => 100,
        'stage' => 'render_cuts',
    ]);

    Livewire::actingAs($this->user)
        ->test(Editor::class, ['video' => $video])
        ->assertViewHas('activeJobId', fn ($value): bool => $value === null);
});
