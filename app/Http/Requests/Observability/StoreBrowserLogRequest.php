<?php

declare(strict_types=1);

namespace App\Http\Requests\Observability;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBrowserLogRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'level' => ['required', 'in:warning,error'],
            'message' => ['required', 'string', 'max:2000'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:2000'],
            'context' => ['nullable', 'array'],
        ];
    }

    public function level(): string
    {
        return (string) $this->validated('level');
    }

    public function message(): string
    {
        return (string) $this->validated('message');
    }

    public function requestId(): ?string
    {
        $requestId = $this->validated('request_id');

        return is_string($requestId) ? $requestId : null;
    }

    public function pageUrl(): ?string
    {
        $url = $this->validated('url');

        return is_string($url) ? $url : null;
    }

    /** @return array<array-key, mixed> */
    public function context(): array
    {
        $context = $this->validated('context');

        return is_array($context) ? $context : [];
    }
}
