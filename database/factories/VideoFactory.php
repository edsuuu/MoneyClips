<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Video> */
final class VideoFactory extends Factory
{
    protected $model = Video::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'uuid' => (string) Str::uuid(),
            'hash' => null,
            'file_size' => 1024 * 1024,
            'mime_type' => 'video/mp4',
            'status' => VideoStatusEnum::AwaitingUpload,
            'upload_id' => (string) Str::uuid(),
            'progress' => 0,
        ];
    }

    public function uploaded(): self
    {
        return $this->state(fn (): array => [
            'status' => VideoStatusEnum::Uploaded,
            'upload_id' => null,
        ]);
    }

    public function packaging(): self
    {
        return $this->state(fn (): array => [
            'status' => VideoStatusEnum::Packaging,
            'upload_id' => null,
            'hls_remote_id' => (string) Str::uuid(),
        ]);
    }

    public function ready(): self
    {
        return $this->state(fn (): array => [
            'status' => VideoStatusEnum::Ready,
            'upload_id' => null,
            'hls_remote_id' => (string) Str::uuid(),
            'hash' => md5('conteudo'),
            'progress' => 100,
            'duration_seconds' => 40,
            'width' => 1280,
            'height' => 720,
            'renditions' => ['360p', '720p'],
            'ready_at' => now(),
        ]);
    }
}
