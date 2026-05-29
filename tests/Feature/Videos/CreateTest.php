<?php

declare(strict_types=1);

use App\Livewire\Videos\Create;
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

test('create page shows the clip generation modes', function (): void {
    $this->actingAs($this->user)
        ->get(route('videos.create'))
        ->assertOk()
        ->assertSee('Manual')
        ->assertSee('Automático 60s')
        ->assertSee('IA escolhe');
});

test('starts ingest when url does not exist', function (): void {
    $mockProvider = mock(VideoProcessorProviderInterface::class);
    $mockProvider->shouldReceive('ingest')
        ->once()
        ->andReturn(['job_id' => 'external-job-123']);
    $this->app->instance(VideoProcessorProviderInterface::class, $mockProvider);

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('url', 'https://www.youtube.com/watch?v=12345')
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirectContains('/videos/');
});

test('sequential automatic mode stores auto settings for full coverage clips', function (): void {
    $mockProvider = mock(VideoProcessorProviderInterface::class);
    $mockProvider->shouldReceive('ingest')
        ->once()
        ->andReturn(['job_id' => 'external-job-123']);
    $this->app->instance(VideoProcessorProviderInterface::class, $mockProvider);

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('url', 'https://www.youtube.com/watch?v=sequential123')
        ->set('processingMode', 'sequential')
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirectContains('/videos/')
        ->assertRedirectContains('/editor');

    $video = Video::query()->latest()->first();

    expect($video)->not->toBeNull();
    expect($video?->is_auto)->toBeTrue();
    expect($video?->auto_mode)->toBe('sequential');
    expect($video?->auto_clip_count)->toBeNull();
});

test('ai mode allows empty clip count and stores requested amount when provided', function (): void {
    $mockProvider = mock(VideoProcessorProviderInterface::class);
    $mockProvider->shouldReceive('ingest')
        ->twice()
        ->andReturn(['job_id' => 'external-job-456']);
    $this->app->instance(VideoProcessorProviderInterface::class, $mockProvider);

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('url', 'https://www.youtube.com/watch?v=ai123')
        ->set('processingMode', 'ai')
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirectContains('/videos/')
        ->assertRedirectContains('/editor');

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('url', 'https://www.youtube.com/watch?v=ai456')
        ->set('processingMode', 'ai')
        ->set('clipCount', 5)
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirectContains('/videos/')
        ->assertRedirectContains('/editor');

    $videos = Video::query()->latest()->take(2)->get()->values();
    $videoWithCount = $videos->firstWhere('url', 'https://www.youtube.com/watch?v=ai456');
    $videoWithoutCount = $videos->firstWhere('url', 'https://www.youtube.com/watch?v=ai123');

    expect($videoWithoutCount)->not->toBeNull();
    expect($videoWithoutCount?->is_auto)->toBeTrue();
    expect($videoWithoutCount?->auto_mode)->toBe('ai');
    expect($videoWithoutCount?->auto_clip_count)->toBeNull();

    expect($videoWithCount)->not->toBeNull();
    expect($videoWithCount?->is_auto)->toBeTrue();
    expect($videoWithCount?->auto_mode)->toBe('ai');
    expect($videoWithCount?->auto_clip_count)->toBe(5);
});

test('reuses cached video data and redirects to transcript if no legendado file exists', function (): void {
    $mockProvider = mock(VideoProcessorProviderInterface::class);
    $mockProvider->shouldNotReceive('ingest');

    $this->app->instance(VideoProcessorProviderInterface::class, $mockProvider);

    // Create the existing video with original file and transcript
    $existing = Video::query()->create([
        'url' => 'https://www.youtube.com/watch?v=12345',
        'status_id' => Status::idFor('waiting_transcript_review'),
        'title' => 'Test Video',
        'duration_seconds' => 120.0,
        'source_provider' => 'youtube',
        'external_video_id' => '12345',
    ]);

    $existing->transcript()->create([
        'language' => 'pt',
        'duration_seconds' => 120.0,
        'raw_text' => 'Hello World',
        'active_text_source' => 'raw',
    ]);

    $existing->files()->create([
        'type' => 'original',
        'path' => 'videos/123/original.mp4',
        'disk' => 'minio',
    ]);

    $existing->payloads()->create([
        'type' => 'transcript_raw',
        'payload' => ['segments' => []],
    ]);

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('url', 'https://www.youtube.com/watch?v=12345')
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirectContains('/videos/');

    // Assert a new video is created in the database with the copied data
    $this->assertDatabaseCount('videos', 2);
    $newVideo = Video::query()->where('id', '!=', $existing->id)->first();
    expect($newVideo->title)->toBe('Test Video');
    expect($newVideo->status_id)->toBe(Status::idFor('waiting_transcript_review'));
    expect($newVideo->transcript->raw_text)->toBe('Hello World');
    expect($newVideo->fileOfType('original')->path)->toBe('videos/123/original.mp4');
    expect($newVideo->fileOfType('legendado'))->toBeNull();
});

test('reuses cached video data and redirects to editor if legendado file exists', function (): void {
    $mockProvider = mock(VideoProcessorProviderInterface::class);
    $mockProvider->shouldNotReceive('ingest');

    $this->app->instance(VideoProcessorProviderInterface::class, $mockProvider);

    // Create the existing video with original file, transcript and legendado file
    $existing = Video::query()->create([
        'url' => 'https://www.youtube.com/watch?v=12345',
        'status_id' => Status::idFor('full_subtitled'),
        'title' => 'Test Video',
        'duration_seconds' => 120.0,
        'source_provider' => 'youtube',
        'external_video_id' => '12345',
    ]);

    $existing->transcript()->create([
        'language' => 'pt',
        'duration_seconds' => 120.0,
        'raw_text' => 'Hello World',
        'active_text_source' => 'raw',
    ]);

    $existing->files()->create([
        'type' => 'original',
        'path' => 'videos/123/original.mp4',
        'disk' => 'minio',
    ]);

    $existing->files()->create([
        'type' => 'legendado',
        'path' => 'videos/123/legendado.mp4',
        'disk' => 'minio',
    ]);

    $existing->payloads()->create([
        'type' => 'transcript_raw',
        'payload' => ['segments' => []],
    ]);

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('url', 'https://www.youtube.com/watch?v=12345')
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirectContains('/videos/')
        ->assertRedirectContains('/editor');

    // Assert a new video is created in the database with the copied data
    $this->assertDatabaseCount('videos', 2);
    $newVideo = Video::query()->where('id', '!=', $existing->id)->first();
    expect($newVideo->title)->toBe('Test Video');
    expect($newVideo->status_id)->toBe(Status::idFor('full_subtitled'));
    expect($newVideo->fileOfType('original')->path)->toBe('videos/123/original.mp4');
    expect($newVideo->fileOfType('legendado')->path)->toBe('videos/123/legendado.mp4');
});
