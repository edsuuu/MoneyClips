<?php

declare(strict_types=1);

namespace App\Livewire\Social;

use App\Models\SocialAccount;
use App\Services\SocialPublishing\SocialPublisherRegistry;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

/**
 * Conecta contas das plataformas guardando o token OAuth (criptografado).
 * Enquanto não há o fluxo OAuth redirect completo, o usuário cola o access
 * token/IDs obtidos no console de desenvolvedor de cada plataforma.
 */
final class Accounts extends Component
{
    #[Validate('required|string')]
    public string $platform = 'youtube';

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $external_account_id = '';

    #[Validate('required|string')]
    public string $access_token = '';

    public string $refresh_token = '';

    #[Validate('nullable|string')]
    public string $token_expires_at = '';

    /** JSON livre com extras por plataforma (ig_user_id, page_id, privacy_level...). */
    public string $meta = '';

    public function save(): void
    {
        $this->validate();

        if (! in_array($this->platform, SocialAccount::PLATFORMS, true)) {
            Flux::toast('Plataforma inválida.', variant: 'danger');

            return;
        }

        $meta = null;
        if (mb_trim($this->meta) !== '') {
            $decoded = json_decode($this->meta, true);
            if (! is_array($decoded)) {
                Flux::toast('O campo Meta precisa ser um JSON válido.', variant: 'danger');

                return;
            }

            $meta = $decoded;
        }

        $ownerId = $this->currentUserId();
        $tokenExpiresAt = $this->parseTokenExpiresAt();

        if ($this->token_expires_at !== '' && ! $tokenExpiresAt instanceof CarbonInterface) {
            Flux::toast('Informe uma data de expiração válida.', variant: 'danger');

            return;
        }

        SocialAccount::query()->create([
            'user_id' => $ownerId,
            'platform' => $this->platform,
            'name' => $this->name,
            'external_account_id' => $this->external_account_id ?: null,
            'access_token' => $this->access_token,
            'refresh_token' => $this->refresh_token ?: null,
            'token_expires_at' => $tokenExpiresAt,
            'meta' => $meta,
            'is_active' => true,
        ]);

        $this->reset(['name', 'external_account_id', 'access_token', 'refresh_token', 'token_expires_at', 'meta']);
        Flux::toast('Conta conectada.');
    }

    public function toggleActive(int $id): void
    {
        $account = SocialAccount::query()
            ->where('user_id', $this->currentUserId())
            ->find($id);

        if ($account instanceof SocialAccount) {
            $account->update(['is_active' => ! $account->is_active]);
        }
    }

    public function delete(int $id): void
    {
        SocialAccount::query()
            ->where('user_id', $this->currentUserId())
            ->whereKey($id)
            ->delete();

        Flux::toast('Conta removida.');
    }

    public function render(SocialPublisherRegistry $registry): View
    {
        return view('livewire.social.accounts', [
            'platformLabels' => $registry->labels(),
            'accounts' => SocialAccount::query()
                ->where('user_id', $this->currentUserId())
                ->latest()
                ->get(),
        ]);
    }

    private function currentUserId(): int
    {
        $userId = Auth::id();
        abort_unless(is_int($userId), 403);

        return $userId;
    }

    private function parseTokenExpiresAt(): ?CarbonInterface
    {
        if ($this->token_expires_at === '') {
            return null;
        }

        try {
            return Date::parse($this->token_expires_at);
        } catch (Throwable) {
            return null;
        }
    }
}
