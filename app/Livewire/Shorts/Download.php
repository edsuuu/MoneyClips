<?php

declare(strict_types=1);

namespace App\Livewire\Shorts;

use App\Livewire\Concerns\WithToasts;
use App\Services\Youtube\ShortsDownloaderClient;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * Tela para enviar a URL de um canal ao microserviço download-shorts.
 *
 * O microserviço baixa os Shorts, sobe para o storage (Contabo) e mantém os
 * itens no banco DELE (dispatch_on_complete=false) — esta tela só cria o job
 * e acompanha o progresso via polling do status.
 */
final class Download extends Component
{
    use WithToasts;

    private const string SESSION_KEY = 'shorts_downloader.last_job_id';

    /** Status do job que indicam que o microserviço ainda está trabalhando. */
    private const array RUNNING_STATUSES = ['queued', 'listing', 'processing'];

    public string $channelUrl = '';

    public ?string $jobId = null;

    /** @var array<string, mixed>|null */
    public ?array $status = null;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'channelUrl' => ['required', 'url'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'channelUrl.required' => 'Informe a URL do canal.',
            'channelUrl.url' => 'A URL informada não é válida.',
        ];
    }

    public function mount(): void
    {
        $lastJobId = (string) (session(self::SESSION_KEY));
        if ($lastJobId !== '') {
            $this->jobId = $lastJobId;
            $this->refreshStatus();
        }
    }

    public function start(ShortsDownloaderClient $client): void
    {
        /** @var array{channelUrl: string} $validated */
        $validated = $this->validate();

        try {
            $this->jobId = $client->createJob($validated['channelUrl']);
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível criar o job. O microserviço download-shorts está no ar?', 'danger');

            return;
        }

        session([self::SESSION_KEY => $this->jobId]);
        $this->status = null;
        $this->channelUrl = '';
        $this->toast('Download enviado ao microserviço. Acompanhe o progresso abaixo.');
        $this->refreshStatus();
    }

    /** Atualiza o status do job (chamado pelo wire:poll enquanto roda). */
    public function refreshStatus(): void
    {
        if ($this->jobId === null) {
            return;
        }

        try {
            $this->status = resolve(ShortsDownloaderClient::class)->jobStatus($this->jobId);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /** Esquece o job acompanhado para enviar outro canal. */
    public function clearJob(): void
    {
        $this->jobId = null;
        $this->status = null;
        session()->forget(self::SESSION_KEY);
    }

    public function render(): View
    {
        $state = (string) ($this->status['status'] ?? '');

        return view('livewire.shorts.download', [
            'state' => $state,
            'isRunning' => $this->jobId !== null
                && ($this->status === null || in_array($state, self::RUNNING_STATUSES, true)),
            'counts' => [
                'total' => (int) ($this->status['total'] ?? 0),
                'pending' => (int) ($this->status['pending'] ?? 0),
                'processing' => (int) ($this->status['processing'] ?? 0),
                'completed' => (int) ($this->status['completed'] ?? 0),
                'failed' => (int) ($this->status['failed'] ?? 0),
            ],
            'lastError' => (string) ($this->status['last_error'] ?? ''),
        ]);
    }
}
