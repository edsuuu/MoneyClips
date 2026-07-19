<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observability;

use App\Http\Controllers\Controller;
use App\Models\ServiceHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
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
