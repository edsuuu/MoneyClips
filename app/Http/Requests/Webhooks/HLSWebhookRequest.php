<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

final class HLSWebhookRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'video_uuid' => ['required', 'string'],
            'status' => ['required', 'in:done,failed,rejected,progress'],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'error' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'hash' => ['nullable', 'string', 'size:32'],
            'renditions' => ['nullable', 'array'],
            'renditions.*' => ['string', 'max:16'],
            'poster' => ['nullable', 'boolean'],
            'audio' => ['nullable', 'boolean'],
            'storyboard' => ['nullable', 'array'],
            'storyboard.cols' => ['nullable', 'integer', 'min:1'],
            'storyboard.rows' => ['nullable', 'integer', 'min:1'],
            'storyboard.interval' => ['nullable', 'numeric', 'min:0'],
            'storyboard.tile_width' => ['nullable', 'integer', 'min:1'],
            'storyboard.tile_height' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function videoUuid(): string
    {
        return (string) $this->validated('video_uuid');
    }

    public function status(): string
    {
        return (string) $this->validated('status');
    }

    public function progress(): int
    {
        return (int) $this->validated('progress');
    }

    public function error(): ?string
    {
        $error = $this->validated('error');

        return is_string($error) ? $error : null;
    }

    public function durationSeconds(): ?int
    {
        $duration = $this->validated('duration_seconds');

        return is_numeric($duration) ? (int) $duration : null;
    }

    public function width(): ?int
    {
        $width = $this->validated('width');

        return is_numeric($width) ? (int) $width : null;
    }

    public function height(): ?int
    {
        $height = $this->validated('height');

        return is_numeric($height) ? (int) $height : null;
    }

    public function hash(): ?string
    {
        $hash = $this->validated('hash');

        return is_string($hash) ? $hash : null;
    }

    /** @return list<string> */
    public function renditions(): array
    {
        $renditions = $this->validated('renditions');

        if (! is_array($renditions)) {
            return [];
        }

        /** @var list<string> $values */
        $values = array_values($renditions);

        return $values;
    }

    public function hasPoster(): bool
    {
        return (bool) $this->validated('poster');
    }

    public function hasAudio(): bool
    {
        return (bool) $this->validated('audio');
    }

    /** @return array<string, int|float>|null */
    public function storyboard(): ?array
    {
        $storyboard = $this->validated('storyboard');

        if (! is_array($storyboard) || $storyboard === []) {
            return null;
        }

        return [
            'cols' => (int) ($storyboard['cols'] ?? 0),
            'rows' => (int) ($storyboard['rows'] ?? 0),
            'interval' => (float) ($storyboard['interval'] ?? 0),
            'tile_width' => (int) ($storyboard['tile_width'] ?? 0),
            'tile_height' => (int) ($storyboard['tile_height'] ?? 0),
        ];
    }
}
