<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VideoStatusEnum;
use App\Models\File;
use App\Models\User;
use App\Models\Video;
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
            'name' => null,
            'status' => VideoStatusEnum::AwaitingUpload,
            'progress' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Video $video): void {
            if ($video->status === VideoStatusEnum::Downloading) {
                return;
            }

            $video->files()->create([
                'type' => File::ORIGINAL,
                'path' => $video->originalPath(),
                'upload_id' => $video->status === VideoStatusEnum::AwaitingUpload ? (string) Str::uuid() : null,
                'size' => 1024 * 1024,
                'mime_type' => 'video/mp4',
            ]);
        });
    }

    public function downloading(): self
    {
        return $this->state(fn (): array => ['status' => VideoStatusEnum::Downloading]);
    }

    public function uploaded(): self
    {
        return $this->state(fn (): array => ['status' => VideoStatusEnum::Uploaded]);
    }

    public function packaging(): self
    {
        return $this->state(fn (): array => ['status' => VideoStatusEnum::Packaging]);
    }

    public function ready(): self
    {
        return $this->state(fn (): array => [
            'status' => VideoStatusEnum::Ready,
            'hash' => md5('conteudo'),
            'progress' => 100,
            'duration_seconds' => 40,
            'width' => 1280,
            'height' => 720,
            'ready_at' => now(),
        ]);
    }
}
