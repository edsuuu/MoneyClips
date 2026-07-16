<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSetting>
 */
final class PlatformSettingFactory extends Factory
{
    protected $model = PlatformSetting::class;

    public function definition(): array
    {
        $platform = (string) fake()->unique()->randomElement(['youtube', 'tiktok', 'tiktok_official', 'instagram', 'facebook', 'kwai']);

        return [
            'platform' => $platform,
            'display_name' => ucfirst($platform),
            'enabled' => false,
        ];
    }
}
