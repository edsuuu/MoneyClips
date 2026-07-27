<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

final class DownloadVideoWebhookRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'video_uuid' => ['required', 'string'],
            'status' => ['required', 'in:completed,failed'],
            'error' => ['nullable', 'string'],
            'size_bytes' => ['nullable', 'integer', 'min:1'],
            'title' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
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

    public function error(): ?string
    {
        $error = $this->validated('error');

        return is_string($error) ? $error : null;
    }

    public function sizeBytes(): ?int
    {
        $size = $this->validated('size_bytes');

        return is_numeric($size) ? (int) $size : null;
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
}
