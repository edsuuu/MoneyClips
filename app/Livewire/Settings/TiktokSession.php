<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WithToasts;
use App\Services\TikTok\TiktokPostService;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * Gestão da sessão do TikTok no uploader (microserviço). O login real roda no
 * serviço Node (Playwright); aqui o Laravel apenas orquestra: dispara o login,
 * injeta uma sessão exportada localmente e mostra o status (GET /session).
 */
final class TiktokSession extends Component
{
    use WithToasts;

    /**
     * SessionView do uploader (account, has_cookies, expired, valid).
     *
     * @var array<string, mixed>|null
     */
    public ?array $session = null;

    public bool $serviceUp = false;

    /** Re-login limpo: apaga os cookies atuais antes de logar. */
    public bool $forceLogin = false;

    /** JSON de cookies exportados de um login local, para injeção. */
    public string $cookiesJson = '';

    public function mount(TiktokPostService $service): void
    {
        $this->loadStatus($service);
    }

    public function refresh(TiktokPostService $service): void
    {
        $this->loadStatus($service);
        $this->toast('Status da sessão atualizado.');
    }

    public function login(TiktokPostService $service): void
    {
        try {
            $this->session = $service->login($this->forceLogin);
            $valid = (bool) ($this->session['valid'] ?? false);
            $this->serviceUp = true;
            $this->toast(
                $valid ? 'Login concluído — sessão válida.' : 'Login executado, mas a sessão não ficou válida.',
                $valid ? 'success' : 'warning',
            );
        } catch (Throwable $throwable) {
            $this->toast('Falha no login: '.$throwable->getMessage(), 'danger');
        }
    }

    public function inject(TiktokPostService $service): void
    {
        $decoded = json_decode($this->cookiesJson, true);
        if (! is_array($decoded) || $decoded === []) {
            $this->toast('Cole um JSON de cookies válido (array não vazio).', 'danger');

            return;
        }

        try {
            /** @var array<int, array<string, mixed>> $decoded */
            $this->session = $service->injectSession($decoded);
            $this->serviceUp = true;
            $this->cookiesJson = '';
            $this->toast('Sessão injetada no uploader.');
        } catch (Throwable $throwable) {
            $this->toast('Falha ao injetar sessão: '.$throwable->getMessage(), 'danger');
        }
    }

    public function render(): View
    {
        return view('livewire.settings.tiktok-session');
    }

    private function loadStatus(TiktokPostService $service): void
    {
        $this->serviceUp = $service->health();
        $this->session = $this->serviceUp ? $service->session() : null;
    }
}
