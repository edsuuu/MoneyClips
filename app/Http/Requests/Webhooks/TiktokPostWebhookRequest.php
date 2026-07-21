<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

final class TiktokPostWebhookRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'job_id' => ['required', 'string'],
            'status' => ['required', 'in:completed,dry-run,restricted,failed'],
            'detail' => ['nullable', 'string'],
            'error' => ['nullable', 'string'],
            'session_status' => ['nullable', 'in:valid,invalid,unknown'],
            'refreshed_cookies' => ['nullable', 'array'],
            'account_id' => ['nullable', 'string'],
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

    public function detail(): ?string
    {
        $detail = $this->validated('detail');

        return is_string($detail) ? $detail : null;
    }

    public function error(): ?string
    {
        $error = $this->validated('error');

        return is_string($error) ? $error : null;
    }

    public function sessionStatus(): ?string
    {
        $sessionStatus = $this->validated('session_status');

        return is_string($sessionStatus) ? $sessionStatus : null;
    }

    /** @return array<array-key, mixed> */
    public function refreshedCookies(): array
    {
        $cookies = $this->validated('refreshed_cookies');

        return is_array($cookies) ? $cookies : [];
    }

    public function accountId(): ?int
    {
        $accountId = $this->validated('account_id');

        return is_numeric($accountId) ? (int) $accountId : null;
    }
}
