<?php

declare(strict_types=1);

namespace App\Http\Requests\Observability;

use Illuminate\Foundation\Http\FormRequest;

final class StoreHeartbeatRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', 'max:64'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:32'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'memory_mb' => ['nullable', 'integer', 'min:0'],
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

    public function version(): ?string
    {
        $version = $this->validated('version');

        return is_string($version) ? $version : null;
    }

    public function uptimeSeconds(): int
    {
        return (int) $this->validated('uptime_seconds');
    }

    public function memoryMb(): ?int
    {
        $memory = $this->validated('memory_mb');

        return is_numeric($memory) ? (int) $memory : null;
    }
}
