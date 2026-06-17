<?php

declare(strict_types=1);

namespace App\Livewire\Downloads;

use App\Livewire\Concerns\WithToasts;
use App\Services\Youtube\DownloadYoutubeService;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

final class NewDownload extends Component
{
    use WithToasts;

    private const string SESSION_KEY = 'download_youtube.last_job_id';

    private const array RUNNING_STATUSES = ['accepted', 'queued', 'listing', 'processing'];

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

    public function start(): void
    {
        /** @var array{channelUrl: string} $validated */
        $validated = $this->validate();

        try {
            $this->jobId = resolve(DownloadYoutubeService::class)->createDownload($validated['channelUrl']);
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível criar o download no microserviço.', 'danger');

            return;
        }

        session([self::SESSION_KEY => $this->jobId]);
        $this->status = null;
        $this->channelUrl = '';
        $this->toast('Download enviado. Acompanhe o progresso abaixo.');
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        if ($this->jobId === null) {
            return;
        }

        try {
            $this->status = resolve(DownloadYoutubeService::class)->getJobStatus($this->jobId);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    public function clearJob(): void
    {
        $this->jobId = null;
        $this->status = null;
        session()->forget(self::SESSION_KEY);
    }

    public function render(): View
    {
        $state = (string) ($this->status['status'] ?? '');

        return view('livewire.downloads.new-download', [
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
