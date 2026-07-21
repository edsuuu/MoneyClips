<?php

declare(strict_types=1);

namespace App\Http\Requests\Observability;

use Illuminate\Foundation\Http\FormRequest;

final class StoreServiceLogsRequest extends FormRequest
{
    private const int MAX_ENTRIES = 500;

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', 'max:64'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'entries' => ['required', 'array', 'min:1', 'max:'.self::MAX_ENTRIES],
            'entries.*.level' => ['required', 'in:debug,info,warn,error'],
            'entries.*.message' => ['required', 'string', 'max:10000'],
            'entries.*.context' => ['nullable', 'array'],
            'entries.*.logged_at' => ['nullable', 'date'],
        ];
    }

    public function service(): string
    {
        return (string) $this->validated('service');
    }

    public function hostname(): ?string
    {
        $hostname = $this->validated('hostname');

        return is_string($hostname) ? $hostname : null;
    }

    /** @return list<array{level: string, message: string, context?: array<string, mixed>|null, logged_at?: string|null}> */
    public function entries(): array
    {
        /** @var list<array{level: string, message: string, context?: array<string, mixed>|null, logged_at?: string|null}> $entries */
        $entries = $this->validated('entries');

        return $entries;
    }
}
