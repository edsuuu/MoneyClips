<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScheduleSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleSlot>
 */
final class ScheduleSlotFactory extends Factory
{
    protected $model = ScheduleSlot::class;

    public function definition(): array
    {
        return [
            'slot_date' => now()->addDay()->toDateString(),
            'slot_time' => sprintf('%02d:%02d:00', fake()->numberBetween(8, 21), fake()->numberBetween(0, 59)),
            'youtube_short_id' => null,
            'is_active' => true,
            'dispatched_at' => null,
        ];
    }

    public function dispatched(): self
    {
        return $this->state(fn (): array => ['dispatched_at' => now()]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
