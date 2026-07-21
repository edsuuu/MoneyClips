<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Observability\StoreHeartbeatRequest;
use App\Http\Requests\Observability\StoreServiceLogsRequest;
use App\Models\ServiceHeartbeat;
use App\Models\ServiceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

final class ObservabilityController extends Controller
{
    public function logs(StoreServiceLogsRequest $request): JsonResponse
    {
        $now = now();
        $service = $request->service();
        $hostname = $request->hostname();

        $rows = array_map(static fn (array $entry): array => [
            'service' => $service,
            'hostname' => $hostname,
            'level' => $entry['level'],
            'message' => $entry['message'],
            'context' => isset($entry['context']) ? json_encode($entry['context']) : null,
            'logged_at' => isset($entry['logged_at']) ? Date::parse($entry['logged_at']) : $now,
            'created_at' => $now,
        ], $request->entries());

        ServiceLog::query()->insert($rows);

        return response()->json(['stored' => count($rows)]);
    }

    public function heartbeat(StoreHeartbeatRequest $request): JsonResponse
    {
        ServiceHeartbeat::query()->updateOrCreate(
            ['service' => $request->service()],
            [
                'hostname' => $request->hostname(),
                'version' => $request->version(),
                'uptime_seconds' => $request->uptimeSeconds(),
                'memory_mb' => $request->memoryMb(),
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['status' => 'ok']);
    }
}
