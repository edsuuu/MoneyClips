<?php

declare(strict_types=1);

use App\Enums\VideoStatusEnum;
use App\Jobs\StartYoutubeDownloadJob;
use App\Livewire\Uploads\Create;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    Bus::fake([StartYoutubeDownloadJob::class]);
});

it('rejects a URL that is not a youtube video', function (string $url): void {
    Livewire::test(Create::class)
        ->set('youtubeUrl', $url)
        ->call('fetchYoutubeMetadata')
        ->assertHasErrors(['youtubeUrl']);

    Http::assertNothingSent();
})->with([
    'vazia' => '',
    'não é URL' => 'isso não é url',
    'outro site' => 'https://vimeo.com/123456',
    'canal, não vídeo' => 'https://www.youtube.com/@canal',
]);

it('shows the preview when the video exists', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response([
        'youtube_id' => 'abc123',
        'title' => 'Meu vídeo',
        'duration_seconds' => 3725,
        'width' => 1920,
        'height' => 1080,
        'channel' => 'Canal',
        'thumbnail' => 'https://i.ytimg.com/vi/abc123/maxresdefault.jpg',
    ])]);

    Livewire::test(Create::class)
        ->set('youtubeUrl', 'https://www.youtube.com/watch?v=abc123')
        ->call('fetchYoutubeMetadata')
        ->assertHasNoErrors()
        ->assertSet('youtubePreview.title', 'Meu vídeo')
        ->assertSet('youtubePreview.durationLabel', '1h02min')
        ->assertSet('youtubePreview.resolutionLabel', '1080p')
        ->assertSet('youtubePreview.thumbnailUrl', 'https://i.ytimg.com/vi/abc123/maxresdefault.jpg')
        ->assertSee('Importar vídeo');
});

it('caps the shown resolution at 1080p for higher-res sources', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response([
        'youtube_id' => 'abc123',
        'title' => 'Vídeo 4K',
        'duration_seconds' => 60,
        'width' => 3840,
        'height' => 2160,
        'channel' => 'Canal',
    ])]);

    Livewire::test(Create::class)
        ->set('youtubeUrl', 'https://www.youtube.com/watch?v=abc123')
        ->call('fetchYoutubeMetadata')
        ->assertSet('youtubePreview.resolutionLabel', '1080p')
        ->assertSet('youtubePreview.thumbnailUrl', null);
});

it('shows an error when the video does not exist', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response(['detail' => 'video unavailable'], 404)]);

    Livewire::test(Create::class)
        ->set('youtubeUrl', 'https://www.youtube.com/watch?v=sumiu123')
        ->call('fetchYoutubeMetadata')
        ->assertHasErrors(['youtubeUrl'])
        ->assertSet('youtubePreview', null);
});

it('shows an error when the service is down', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response('boom', 500)]);

    Livewire::test(Create::class)
        ->set('youtubeUrl', 'https://www.youtube.com/watch?v=abc123')
        ->call('fetchYoutubeMetadata')
        ->assertHasErrors(['youtubeUrl']);
});

it('creates the downloading video and queues the job on confirm', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response([
        'youtube_id' => 'abc123',
        'title' => 'Meu vídeo',
        'duration_seconds' => 90,
        'width' => 1920,
        'height' => 1080,
        'channel' => 'Canal',
    ])]);

    Livewire::test(Create::class)
        ->set('youtubeUrl', 'https://youtu.be/abc123')
        ->call('fetchYoutubeMetadata')
        ->call('confirmYoutubeImport')
        ->assertRedirect(route('uploads.index'));

    $video = Video::query()->sole();

    expect($video->status)->toBe(VideoStatusEnum::Downloading)
        ->and($video->name)->toBe('Meu vídeo')
        ->and($video->user_id)->toBe(Auth::id())
        ->and($video->files()->count())->toBe(0);

    Bus::assertDispatched(StartYoutubeDownloadJob::class, fn (StartYoutubeDownloadJob $job): bool => $job->videoId === $video->id
        && $job->url === 'https://youtu.be/abc123');
});

it('does nothing on confirm without a preview', function (): void {
    Livewire::test(Create::class)->call('confirmYoutubeImport');

    expect(Video::query()->count())->toBe(0);
    Bus::assertNotDispatched(StartYoutubeDownloadJob::class);
});

it('clears the field and the preview on cancel', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response([
        'youtube_id' => 'abc123',
        'title' => 'Meu vídeo',
    ])]);

    Livewire::test(Create::class)
        ->set('youtubeUrl', 'https://youtu.be/abc123')
        ->call('fetchYoutubeMetadata')
        ->call('cancelYoutubeImport')
        ->assertSet('youtubeUrl', '')
        ->assertSet('youtubePreview', null);
});
