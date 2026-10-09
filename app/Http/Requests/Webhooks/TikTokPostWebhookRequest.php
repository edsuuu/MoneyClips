<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

final class TikTokPostWebhookRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'job_id' => ['required', 'string'],
            'status' => ['required', 'in:completed,dry-run,restricted,failed'],
            'session_status' => ['required', 'in:valid,invalid,unknown'],
            'error' => ['nullable', 'string'],
            'detail' => ['nullable', 'string'],
            'refreshed_cookies' => ['nullable', 'array', 'max:500'],
            'refreshed_cookies.*' => ['array'],
        ];
    }

    public function jobId(): string
    {
        return (string) $this->validated('job_id');
    }

    public function status(): string
    {
        return (string) $this->validated('status');
    }

    public function sessionStatus(): string
    {
        return (string) $this->validated('session_status');
    }

    public function reason(): string
    {
        $reason = $this->validated('error') ?? $this->validated('detail');

        return is_string($reason) && $reason !== '' ? $reason : 'sem detalhe';
    }

    /** @return list<array<array-key, mixed>> */
    public function refreshedCookies(): array
    {
        $cookies = $this->validated('refreshed_cookies');

        return is_array($cookies) ? array_values(array_filter($cookies, is_array(...))) : [];
    }
}
