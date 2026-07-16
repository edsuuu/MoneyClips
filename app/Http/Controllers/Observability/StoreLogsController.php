<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observability;

use App\Http\Controllers\Controller;
use App\Models\ServiceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Recebe um LOTE de linhas de log de um microserviço (OBSERVABILITY.md):
 * { service, hostname, entries: [{level, message, context?, logged_at}] }.
 * Um único insert em lote — nunca um por linha.
 */
final class StoreLogsController extends Controller
{
    private const int MAX_ENTRIES = 500;

    public function __invoke(Request $request): JsonResponse
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
}
