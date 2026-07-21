<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ServiceHeartbeat;
use App\Models\ServiceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

final class ObservabilityController extends Controller
{
    private const int MAX_ENTRIES = 500;

    public function logs(Request $request): JsonResponse
    {
        /** @var array{service: string, hostname?: string|null, entries: list<array{level: string, message: string, context?: array<string, mixed>|null, logged_at?: string|null}>} $data */
        $data = $request->validate([
            'service' => ['required', 'string', 'max:64'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'entries' => ['required', 'array', 'min:1', 'max:'.self::MAX_ENTRIES],
            'entries.*.level' => ['required', 'in:debug,info,warn,error'],
            'entries.*.message' => ['required', 'string', 'max:10000'],
            'entries.*.context' => ['nullable', 'array'],
            'entries.*.logged_at' => ['nullable', 'date'],
        ]);

        $now = now();
        $rows = array_map(fn (array $entry): array => [
            'service' => $data['service'],
            'hostname' => $data['hostname'] ?? null,
            'level' => $entry['level'],
            'message' => $entry['message'],
            'context' => isset($entry['context']) ? json_encode($entry['context']) : null,
            'logged_at' => isset($entry['logged_at']) ? Date::parse($entry['logged_at']) : $now,
            'created_at' => $now,
        ], $data['entries']);

        ServiceLog::query()->insert($rows);

        return response()->json(['stored' => count($rows)]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var array{service: string, hostname?: string|null, version?: string|null, uptime_seconds?: int|null, memory_mb?: int|null} $data */
        $data = $request->validate([
            'service' => ['required', 'string', 'max:64'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:32'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'memory_mb' => ['nullable', 'integer', 'min:0'],
        ]);

        ServiceHeartbeat::query()->updateOrCreate(
            ['service' => $data['service']],
            [
                'hostname' => $data['hostname'] ?? null,
                'version' => $data['version'] ?? null,
                'uptime_seconds' => (int) ($data['uptime_seconds'] ?? 0),
                'memory_mb' => $data['memory_mb'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['status' => 'ok']);
    }
}
