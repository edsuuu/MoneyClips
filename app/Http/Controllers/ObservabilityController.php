<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Observability\StoreBrowserLogRequest;
use App\Http\Requests\Observability\StoreHeartbeatRequest;
use App\Http\Requests\Observability\StoreServiceLogsRequest;
use App\Http\Resources\StatusResource;
use App\Models\ServiceHeartbeat;
use App\Models\ServiceLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

final class ObservabilityController extends Controller
{
    public function logs(StoreServiceLogsRequest $request): StatusResource
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

        return new StatusResource('ok', extra: ['stored' => count($rows)]);
    }

    public function heartbeat(StoreHeartbeatRequest $request): StatusResource
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

        return new StatusResource('ok');
    }

    /**
     * Unico endpoint da classe que NAO vem de microservico: chega do browser
     * pela /client-logs (sessao web + throttle por usuario), nao pelo
     * VerifyObservabilityToken. Por isso grava no log da aplicacao e nao em
     * service_logs, que e a tabela dos servicos.
     */
    public function browserLog(StoreBrowserLogRequest $request): StatusResource
    {
        Log::log($request->level(), '[browser] '.$request->message(), [
            'request_id' => $request->requestId(),
            'user_id' => Auth::id(),
            'url' => $request->pageUrl(),
            'user_agent' => $request->userAgent(),
            'context' => $request->context(),
        ]);

        return new StatusResource('logged');
    }
}
