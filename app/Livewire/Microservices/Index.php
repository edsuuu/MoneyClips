<?php

declare(strict_types=1);

namespace App\Livewire\Microservices;

use App\Livewire\Concerns\WithToasts;
use App\Services\Microservices\MicroserviceMonitor;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Painel local de status dos microserviços: ping no /health de cada um e um
 * "terminal" que mostra os logs do container selecionado (docker compose logs).
 * Ferramenta de dev — a rota fica atrás de auth.
 */
final class Index extends Component
{
    use WithToasts;

    /** Serviço dockerizado cujo log está aberto no terminal. */
    public string $logService = 'tiktok-uploader';

    public int $logLines = 200;

    /** Liga o auto-refresh (wire:poll) do status e dos logs. */
    public bool $autoRefresh = false;

    public function selectLog(string $service): void
    {
        $this->logService = $service;
    }

    public function refresh(): void
    {
        // O render já relê status e logs; só dá um feedback.
        $this->toast('Atualizado.');
    }

    public function render(MicroserviceMonitor $monitor): View
    {
        return view('livewire.microservices.index', [
            'statuses' => $monitor->statuses(),
            'logs' => $monitor->logs($this->logService, $this->logLines),
        ]);
    }
}
