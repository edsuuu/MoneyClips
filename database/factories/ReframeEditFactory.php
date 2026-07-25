<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReframeEdit;
use App\Models\YoutubeShort;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReframeEdit>
 */
final class ReframeEditFactory extends Factory
{
    protected $model = ReframeEdit::class;

    public function definition(): array
    {
        // Crop 9:16 centrado numa fonte 1920x1080 (coords normalizadas).
        return [
            'uuid' => (string) Str::uuid(),
            'youtube_short_id' => YoutubeShort::factory(),
            'source_path' => sprintf('shorts/%s.mp4', $this->faker->unique()->regexify('[A-Za-z0-9_-]{11}')),
            'source_meta' => ['width' => 1920, 'height' => 1080, 'duration' => 60.0],
            'mode' => 'vertical',
            'keyframes' => [
                ['t' => 0.0, 'mode' => 'vertical', 'regions' => [['x' => 0.3418, 'y' => 0.0, 'w' => 0.3164, 'h' => 1.0]]],
            ],
            'settings' => ['version' => 1, 'background' => '#000000'],
        ];
    }
}
