<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\YoutubeShort;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YoutubeShort>
 */
final class YoutubeShortFactory extends Factory
{
    protected $model = YoutubeShort::class;

    public function definition(): array
    {
        $youtubeId = $this->faker->unique()->regexify('[A-Za-z0-9_-]{11}');

        return [
            'youtube_id' => $youtubeId,
            'channel_url' => 'https://www.youtube.com/@canal-teste',
            'title' => $this->faker->sentence(4),
            'hashtags' => ['#shorts', '#teste'],
            'video_path' => sprintf('shorts/%s.mp4', $youtubeId),
            'youtube_video_id' => null,
            'downloaded_at' => now(),
            'posted_at' => null,
        ];
    }

    public function posted(): self
    {
        return $this->state(fn (): array => [
            'youtube_video_id' => $this->faker->regexify('[A-Za-z0-9_-]{11}'),
            'posted_at' => now(),
        ]);
    }

    public function notDownloaded(): self
    {
        return $this->state(fn (): array => [
            'video_path' => null,
            'downloaded_at' => null,
        ]);
    }
}
