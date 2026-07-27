<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

final class VideoCutEditWebhookRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'edit_uuid' => ['required', 'string'],
            'status' => ['required', 'in:done,failed'],
            'error' => ['nullable', 'string'],
        ];
    }

    public function editUuid(): string
    {
        return (string) $this->validated('edit_uuid');
    }

    public function failed(): bool
    {
        return $this->validated('status') === 'failed';
    }

    public function error(): ?string
    {
        $error = $this->validated('error');

        return is_string($error) ? $error : null;
    }
}
