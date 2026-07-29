<?php

declare(strict_types=1);

namespace App\Livewire\Observability;

use App\Models\ServiceLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;

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

    /** @return list<string> */
    private function knownServices(): array
    {
        return array_values(array_map(
            static fn ($service): string => (string) $service,
            ServiceLog::query()->distinct()->orderBy('service')->pluck('service')->all(),
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
            'media' => 'text-violet-400',
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

        $loggedAt = $log->logged_at;

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

    public function render(): View
    {
        $logs = $this->logsQuery()->get()
            ->map(fn (ServiceLog $log): array => [
                'id' => $log->id,
                'time' => $log->logged_at->format('H:i:s'),
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
            'serviceOptions' => ['all', ...$this->knownServices()],
            'logs' => $logs,
            'errorCount' => $errorCount,
            'detail' => $this->detailViewModel(),
        ]);
    }
}
