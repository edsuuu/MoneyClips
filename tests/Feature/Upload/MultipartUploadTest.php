<?php

declare(strict_types=1);

use App\Jobs\StartHLSPackagingJob;
use App\Models\User;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use App\Services\Upload\MultipartSessionData;
use App\Services\Upload\MultipartUploadInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('requires authentication on every upload endpoint', function (): void {
    auth()->logout();

    $video = Video::factory()->create();

    $this->postJson('/uploads', ['file_size' => 1024, 'mime_type' => 'video/mp4'])->assertUnauthorized();
    $this->getJson(sprintf('/uploads/%s/parts', $video->uuid))->assertUnauthorized();
    $this->postJson(sprintf('/uploads/%s/parts', $video->uuid), ['part_numbers' => [1]])->assertUnauthorized();
    $this->postJson(sprintf('/uploads/%s/complete', $video->uuid), ['parts' => []])->assertUnauthorized();
    $this->deleteJson('/uploads/'.$video->uuid)->assertUnauthorized();
});

it('creates a multipart session and the awaiting video row', function (): void {
    $this->mock(MultipartUploadInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('create')->once()->andReturn(
            new MultipartSessionData('upload-123', 32 * 1024 * 1024, 3),
        );
    });

    $response = $this->postJson('/uploads', [
        'file_size' => 90 * 1024 * 1024,
        'mime_type' => 'video/mp4',
    ])->assertCreated()->assertJsonStructure(['video_uuid', 'upload_id', 'part_size', 'part_count']);

    $video = Video::query()->sole();

    expect($video->status)->toBe(VideoStatusEnum::AwaitingUpload)
        ->and($video->upload_id)->toBe('upload-123')
        ->and($video->user_id)->toBe($this->user->id)
        ->and($response->json('part_count'))->toBe(3);
});

it('refuses a file above the size limit before signing anything', function (): void {
    $this->mock(MultipartUploadInterface::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('create');
    });

    $this->postJson('/uploads', [
        'file_size' => Video::MAX_BYTES + 1,
        'mime_type' => 'video/mp4',
    ])->assertStatus(422)->assertJsonValidationErrors('file_size');

    expect(Video::query()->count())->toBe(0);
});

it('refuses a mime type that is not a supported video', function (): void {
    $this->postJson('/uploads', [
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
    ])->assertStatus(422)->assertJsonValidationErrors('mime_type');
});

it('does not let one user sign parts for another users upload', function (): void {
    $foreign = Video::factory()->create();

    $this->postJson(sprintf('/uploads/%s/parts', $foreign->uuid), ['part_numbers' => [1]])->assertNotFound();
    $this->getJson(sprintf('/uploads/%s/parts', $foreign->uuid))->assertNotFound();
    $this->deleteJson('/uploads/'.$foreign->uuid)->assertNotFound();
});

it('caps how many parts can be signed at once', function (): void {
    $video = Video::factory()->for($this->user)->create();

    $this->postJson(sprintf('/uploads/%s/parts', $video->uuid), [
        'part_numbers' => range(1, 21),
    ])->assertStatus(422)->assertJsonValidationErrors('part_numbers');
});

it('trusts the bucket over the client when completing', function (): void {
    Bus::fake();
    Storage::fake('s3');

    $video = Video::factory()->for($this->user)->create(['file_size' => 1024]);

    $this->mock(MultipartUploadInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('complete')->once();
        $mock->shouldReceive('size')->once()->andReturn(4096);
    });

    $this->postJson(sprintf('/uploads/%s/complete', $video->uuid), [
        'parts' => [['part_number' => 1, 'etag' => '"abc"']],
    ])->assertOk()->assertJson(['status' => 'uploaded']);

    $video->refresh();

    expect($video->file_size)->toBe(4096)
        ->and($video->status)->toBe(VideoStatusEnum::Uploaded)
        ->and($video->upload_id)->toBeNull();

    Bus::assertDispatched(StartHLSPackagingJob::class);
});

it('rejects and deletes an upload that turned out to be oversized', function (): void {
    Bus::fake();
    Storage::fake('s3');

    $video = Video::factory()->for($this->user)->create();
    Storage::disk('s3')->put($video->path(), 'conteudo');

    $this->mock(MultipartUploadInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('complete')->once();
        $mock->shouldReceive('size')->once()->andReturn(Video::MAX_BYTES + 1);
    });

    $this->postJson(sprintf('/uploads/%s/complete', $video->uuid), [
        'parts' => [['part_number' => 1, 'etag' => '"abc"']],
    ])->assertStatus(422);

    $video->refresh();

    expect($video->status)->toBe(VideoStatusEnum::Rejected);
    Storage::disk('s3')->assertMissing($video->path());
    Bus::assertNotDispatched(StartHLSPackagingJob::class);
});

it('aborts an upload in progress and drops the row', function (): void {
    $video = Video::factory()->for($this->user)->create();

    $this->mock(MultipartUploadInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('abort')->once();
    });

    $this->deleteJson('/uploads/'.$video->uuid)->assertOk();

    expect(Video::query()->count())->toBe(0);
});

it('will not abort an upload that already finished', function (): void {
    $video = Video::factory()->for($this->user)->ready()->create();

    $this->deleteJson('/uploads/'.$video->uuid)->assertStatus(409);

    expect(Video::query()->count())->toBe(1);
});
