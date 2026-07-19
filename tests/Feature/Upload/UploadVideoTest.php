<?php

declare(strict_types=1);

use App\Livewire\Upload\Index;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('s3');
    $this->actingAs(User::factory()->create());
});

it('stores the video in the s3 disk and hashes the stored binary', function (): void {
    Livewire::test(Index::class)
        ->set('video', UploadedFile::fake()->create('clip.mp4', 64, 'video/mp4'))
        ->assertHasNoErrors()
        ->assertSet('done', true);

    $video = Video::query()->sole();

    Storage::disk('s3')->assertExists($video->path());

    expect($video->hash)->toBe(md5((string) Storage::disk('s3')->get($video->path())))
        ->and($video->mime_type)->toBe('video/mp4')
        ->and($video->file_size)->toBe(64 * 1024)
        ->and($video->user_id)->toBe(auth()->id());
});

it('rejects a file that is not a video', function (): void {
    Livewire::test(Index::class)
        ->set('video', UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'))
        ->assertHasErrors('video')
        ->assertSet('done', false);

    expect(Video::query()->count())->toBe(0);
});

it('lets the operator upload another after finishing', function (): void {
    Livewire::test(Index::class)
        ->set('video', UploadedFile::fake()->create('clip.mp4', 8, 'video/mp4'))
        ->assertSet('done', true)
        ->call('uploadAnother')
        ->assertSet('done', false)
        ->assertSet('video', null);
});
