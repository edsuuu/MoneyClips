<?php

declare(strict_types=1);

namespace App\Livewire\TiktokAuth;

use App\Http\Controllers\TiktokCookiesController;
use App\Livewire\Concerns\WithToasts;
use App\Services\TikTok\TikTokUploaderClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * Fluxo de "Conectar TikTok": abre uma popup com tiktok.com pra o usuário
 * logar no navegador real, e fica aguardando a extensão Chrome
 * (tiktok-cookie-bridge) postar os cookies em /api/tiktok/cookies/ingest.
 *
 * O polling só roda quando o usuário aperta "Conectar" (mantém UI tranquila).
 */
final class Connect extends Component
{
    use WithToasts;

    public bool $listening = false;

    public string $lastIngestAt = '';

    /** @var array<string, mixed>|null */
    public ?array $session = null;

    public ?bool $serviceUp = null;

    public function startListening(): void
    {
        if (! $this->bridgeConfigured()) {
            $this->toast('Configure TIKTOK_BRIDGE_TOKEN no .env primeiro.', 'danger');

            return;
        }

        $this->listening = true;
        $this->refreshState();
    }

    public function stopListening(): void
    {
        $this->listening = false;
    }

    public function refreshState(): void
    {
        $this->lastIngestAt = (string) Cache::get(TiktokCookiesController::LAST_INGEST_CACHE_KEY, '');
        $this->session = $this->fetchSession();
    }

    public function render(): View
    {
        // Render lê o estado uma vez por ciclo. Quando `listening` está ligado,
        // o blade adiciona um wire:poll que dispara refreshState() periódico.
        if ($this->serviceUp === null) {
            $this->serviceUp = $this->pingUploader();
        }

        if ($this->listening) {
            $this->refreshState();
        }

        return view('livewire.tiktok-auth.connect', [
            'bridgeConfigured' => $this->bridgeConfigured(),
            'ingestEndpoint' => url('/api/tiktok/cookies/ingest'),
            'bridgeToken' => (string) config('services.tiktok_post.bridge_token', ''),
        ]);
    }

    private function bridgeConfigured(): bool
    {
        return ((string) config('services.tiktok_post.bridge_token', '')) !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSession(): ?array
    {
        try {
            return resolve(TikTokUploaderClient::class)->session();
        } catch (Throwable) {
            return null;
        }
    }

    private function pingUploader(): bool
    {
        try {
            return resolve(TikTokUploaderClient::class)->isHealthy();
        } catch (Throwable) {
            return false;
        }
    }
}
