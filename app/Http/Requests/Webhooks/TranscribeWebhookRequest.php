<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

final class TranscribeWebhookRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'string'],
            'status' => ['required', 'in:done,failed'],
            'transcript' => ['nullable', 'array'],
            'error' => ['nullable', 'string'],
        ];
    }

    public function uuid(): string
    {
        return (string) $this->validated('uuid');
    }

    public function failed(): bool
    {
        return $this->validated('status') === 'failed';
    }

    /** @return array<array-key, mixed>|null */
    public function transcript(): ?array
    {
        $transcript = $this->validated('transcript');

        return is_array($transcript) ? $transcript : null;
    }

    public function error(): ?string
    {
        $error = $this->validated('error');

        return is_string($error) ? $error : null;
    }
}
