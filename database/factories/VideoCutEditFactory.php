<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VideoCutEdit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VideoCutEdit>
 */
final class VideoCutEditFactory extends Factory
{
    protected $model = VideoCutEdit::class;

    public function definition(): array
    {
        // Crop 9:16 centrado numa fonte 1920x1080 (coords normalizadas). O corte
        // pai (video_cut_id) é passado pelo teste — VideoCut não tem factory.
        return [
            'uuid' => (string) Str::uuid(),
            'source_meta' => ['width' => 1920, 'height' => 1080, 'duration' => 60.0],
            'mode' => 'vertical',
            'keyframes' => [
                ['t' => 0.0, 'mode' => 'vertical', 'regions' => [['x' => 0.3418, 'y' => 0.0, 'w' => 0.3164, 'h' => 1.0]]],
            ],
            'settings' => ['version' => 1, 'background' => '#000000'],
        ];
    }
}
