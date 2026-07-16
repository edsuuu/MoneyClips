<?php

declare(strict_types=1);

namespace App\Livewire\Observability;

use App\Models\ServiceHeartbeat;
use App\Models\ServiceLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;

/**
 * /observabilidade — stream de logs + saúde dos microserviços (design
 * docs/designs/Observabilidade.dc.html). Fonte: service_logs e
 * service_heartbeats, alimentados por push HTTP dos serviços
 * (OBSERVABILITY.md). Substitui o antigo /microservices via Docker socket.
 */
final class Index extends Component
{
    private const int LOG_LIMIT = 200;

    private const array LEVELS = ['all', 'info', 'warn', 'error'];

    public string $serviceFilter = 'all';

    public string $levelFilter = 'all';

    public string $search = '';

    public ?int $detailId = null;

    public function setServiceFilter(string $service): void
    {
        // Whitelist: só serviços conhecidos (heartbeat ou log já recebido).
        if ($service !== 'all' && ! in_array($service, $this->knownServices(), true)) {
            return;
        }

        $this->serviceFilter = $this->serviceFilter === $service ? 'all' : $service;
    }

    public function setLevelFilter(string $level): void
    {
        $this->levelFilter = in_array($level, self::LEVELS, true) ? $level : 'all';
    }

    public function openDetail(int $logId): void
    {
        $this->detailId = $logId;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    public function filterDetailService(): void
    {
        $log = $this->detailId !== null ? ServiceLog::query()->find($this->detailId) : null;
        if ($log instanceof ServiceLog) {
            $this->serviceFilter = $log->service;
        }

        $this->detailId = null;
    }

    // ── Internos ─────────────────────────────────────────────────────────

    /** @return list<string> */
    private function knownServices(): array
    {
        return array_values(array_map(
            static fn ($service): string => (string) $service,
            ServiceHeartbeat::query()->orderBy('service')->pluck('service')->all(),
        ));
    }

    /** @return Builder<ServiceLog> */
    private function logsQuery(): Builder
    {
        $search = mb_trim($this->search);

        return ServiceLog::query()
            ->when($this->serviceFilter !== 'all', fn (Builder $q): Builder => $q->where('service', $this->serviceFilter))
            ->when(in_array($this->levelFilter, ['info', 'warn', 'error'], true), fn (Builder $q): Builder => $q->where('level', $this->levelFilter))
            ->when($search !== '', fn (Builder $q): Builder => $q->where('message', 'like', '%'.$search.'%'))
            ->latest('id')
            ->limit(self::LOG_LIMIT);
    }

    private function serviceColor(string $service): string
    {
        return match ($service) {
            'tiktok-uploader' => 'text-sky-400',
            'download-shorts' => 'text-violet-400',
            'reencode' => 'text-cyan-400',
            'autocaption' => 'text-amber-300',
            default => 'text-emerald-400',
        };
    }

    /**
     * View model do drawer de detalhe — a view não formata nada.
     *
     * @return array<string, mixed>|null
     */
    private function detailViewModel(): ?array
    {
        $log = $this->detailId !== null ? ServiceLog::query()->find($this->detailId) : null;
        if (! $log instanceof ServiceLog) {
            return null;
        }

        $loggedAt = $log->logged_at->timezone('America/Sao_Paulo');

        return [
            'level' => $log->level,
            'isError' => $log->level === 'error',
            'service' => $log->service,
            'serviceColor' => $this->serviceColor($log->service),
            'message' => $log->message,
            'time' => $loggedAt->format('d/m H:i:s'),
            'fields' => [
                'SERVIÇO' => $log->service,
                'NÍVEL' => mb_strtoupper($log->level),
                'HORÁRIO' => $loggedAt->format('d/m/Y H:i:s'),
                'HOST' => $log->hostname ?? '—',
            ],
            'contextJson' => $log->context !== null && $log->context !== []
                ? json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ];
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.'d';
        }

        if ($hours > 0 || $days > 0) {
            $parts[] = $hours.'h';
        }

        $parts[] = $minutes.'m';

        return implode(' ', $parts);
    }

    private function formatAgo(int $seconds): string
    {
        if ($seconds < 60) {
            return 'há '.$seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return 'há '.$minutes.' min';
        }

        return 'há '.intdiv($minutes, 60).'h';
    }

    public function render(): View
    {
        $services = ServiceHeartbeat::query()->orderBy('service')->get()
            ->map(fn (ServiceHeartbeat $hb): array => [
                'service' => $hb->service,
                'hostname' => $hb->hostname,
                'version' => $hb->version,
                'online' => $hb->isOnline(),
                'uptime' => $hb->isOnline() ? $this->formatUptime($hb->uptime_seconds) : '—',
                'memory' => $hb->memory_mb !== null && $hb->isOnline() ? $hb->memory_mb.' MB' : '—',
                'lastSeen' => $this->formatAgo((int) abs($hb->last_seen_at->diffInSeconds(now()))),
                'lastSeenExact' => 'às '.$hb->last_seen_at->timezone('America/Sao_Paulo')->format('H:i'),
                'selected' => $this->serviceFilter === $hb->service,
            ])->values()->all();

        $logs = $this->logsQuery()->get()
            ->map(fn (ServiceLog $log): array => [
                'id' => $log->id,
                'time' => $log->logged_at->timezone('America/Sao_Paulo')->format('H:i:s'),
                'level' => $log->level,
                'isError' => $log->level === 'error',
                'service' => $log->service,
                'message' => $log->message,
                'hasContext' => $log->context !== null && $log->context !== [],
                'colorClass' => $this->serviceColor($log->service),
            ])->values()->all();

        $errorCount = ServiceLog::query()
            ->when($this->serviceFilter !== 'all', fn (Builder $q): Builder => $q->where('service', $this->serviceFilter))
            ->where('level', 'error')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return view('livewire.observability.index', [
            'services' => $services,
            'serviceOptions' => ['all', ...array_column($services, 'service')],
            'logs' => $logs,
            'errorCount' => $errorCount,
            'detail' => $this->detailViewModel(),
        ]);
    }
}
