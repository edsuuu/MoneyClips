<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

final class CutWebhookRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'cut_uuid' => ['required', 'string'],
            'status' => ['required', 'in:done,failed'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'audio' => ['nullable', 'boolean'],
            'error' => ['nullable', 'string'],
        ];
    }

    public function cutUuid(): string
    {
        return (string) $this->validated('cut_uuid');
    }

    public function failed(): bool
    {
        return $this->validated('status') === 'failed';
    }

    public function hasAudio(): bool
    {
        return (bool) $this->validated('audio', false);
    }

    public function error(): ?string
    {
        $error = $this->validated('error');

        return is_string($error) ? $error : null;
    }
}
