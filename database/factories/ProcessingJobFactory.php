<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProcessingJob;
use App\Models\YoutubeShort;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProcessingJob>
 */
final class ProcessingJobFactory extends Factory
{
    protected $model = ProcessingJob::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'youtube_short_id' => YoutubeShort::factory(),
            'type' => ProcessingJob::TYPE_REENCODE,
            'status' => 'queued',
            'options' => [],
        ];
    }

    public function template(): self
    {
        return $this->state(fn (): array => ['type' => ProcessingJob::TYPE_TEMPLATE]);
    }

    public function processing(): self
    {
        return $this->state(fn (): array => ['status' => 'processing', 'started_at' => now()]);
    }
}
