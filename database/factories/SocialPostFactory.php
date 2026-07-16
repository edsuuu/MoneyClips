<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SocialPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SocialPost>
 */
final class SocialPostFactory extends Factory
{
    protected $model = SocialPost::class;

    public function definition(): array
    {
        return [
            'platform' => 'youtube',
            'uuid' => (string) Str::uuid(),
            'schedule_slot_id' => null,
            'youtube_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
            'video_key' => 'shorts/'.fake()->uuid().'.mp4',
            'title' => fake()->sentence(4),
            'hashtags' => ['#shorts'],
            'status' => 'completed',
            'requested_at' => now(),
        ];
    }
}
