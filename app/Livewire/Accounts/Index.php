<?php

declare(strict_types=1);

namespace App\Livewire\Accounts;

use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class Index extends Component
{
    use WithToasts;

    public bool $showTiktokModal = false;

    public bool $showYoutubeModal = false;

    public ?int $editingAccountId = null;

    public string $name = '';

    public string $login_email = '';

    public string $login_password = '';

    public bool $is_active = true;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'login_email' => ['required', 'email', 'max:255'],
            'login_password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome ou @handle da conta.',
            'login_email.required' => 'Informe o email de login.',
            'login_email.email' => 'Informe um email válido.',
            'login_password.required' => 'Informe a senha de login.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'name' => 'nome',
            'login_email' => 'email',
            'login_password' => 'senha',
        ];
    }

    public function createTiktok(): void
    {
        $this->resetForm();
        $this->showTiktokModal = true;
    }

    public function editTiktok(int $id): void
    {
        $account = $this->tiktokQuery()->whereKey($id)->first();

        if (! $account instanceof SocialAccount) {
            $this->toast('Conta não encontrada.', 'danger');

            return;
        }

        $this->resetValidation();
        $this->editingAccountId = $account->id;
        $this->name = $account->name;
        $this->login_email = $account->login_email ?? '';
        $this->login_password = $account->login_password ?? '';
        $this->is_active = $account->is_active;
        $this->showTiktokModal = true;
    }

    public function saveTiktok(): void
    {
        $this->validate();

        $payload = [
            'user_id' => $this->currentUserId(),
            'platform' => 'tiktok',
            'name' => $this->name,
            'login_email' => $this->login_email,
            'login_password' => $this->login_password,
            'is_active' => $this->is_active,
        ];

        if ($this->editingAccountId !== null) {
            $account = $this->tiktokQuery()->whereKey($this->editingAccountId)->first();

            if (! $account instanceof SocialAccount) {
                $this->toast('Conta não encontrada.', 'danger');

                return;
            }

            $account->update($payload);
            $this->toast('Conta atualizada.');
        } else {
            SocialAccount::query()->create($payload);
            $this->toast('Conta criada.');
        }

        $this->resetForm();
    }

    public function openYoutube(): void
    {
        $this->showYoutubeModal = true;
    }

    public function toggleActive(int $id): void
    {
        $account = $this->accountQuery()->whereKey($id)->first();
        if (! $account instanceof SocialAccount) {
            return;
        }

        $account->is_active = ! $account->is_active;
        $account->save();
        $this->toast(sprintf('Conta %s.', $account->is_active ? 'ativada' : 'desativada'));
    }

    public function delete(int $id): void
    {
        $this->accountQuery()->whereKey($id)->delete();

        if ($this->editingAccountId === $id) {
            $this->resetForm();
        }

        $this->showYoutubeModal = false;
        $this->toast('Conta removida.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showYoutubeModal = false;
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @return Collection<int, array{id: int, name: string, login_email: string|null, is_active: bool, statusColor: string, statusLabel: string, subtitle: string}>
     */
    private function decorateTiktokAccounts(Collection $accounts): Collection
    {
        return $accounts
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'login_email' => $account->login_email,
                'is_active' => $account->is_active,
                'statusColor' => $this->sessionStatusColor($account->session_status),
                'statusLabel' => $this->sessionStatusLabel($account->session_status),
                'subtitle' => $account->name.($account->login_email !== null && $account->login_email !== '' ? ' · '.$account->login_email : ''),
            ])
            ->values();
    }

    private function sessionStatusColor(?string $status): string
    {
        return match ($status) {
            SocialAccount::SESSION_VALID => 'green',
            SocialAccount::SESSION_INVALID => 'red',
            default => 'zinc',
        };
    }

    private function sessionStatusLabel(?string $status): string
    {
        return match ($status) {
            SocialAccount::SESSION_VALID => 'Sessão válida',
            SocialAccount::SESSION_INVALID => 'Sessão inválida',
            default => 'Sessão desconhecida',
        };
    }

    /**
     * @return Builder<SocialAccount>
     */
    private function accountQuery(): Builder
    {
        return SocialAccount::query()->where('user_id', $this->currentUserId());
    }

    /**
     * @return Builder<SocialAccount>
     */
    private function tiktokQuery(): Builder
    {
        return $this->accountQuery()->where('platform', 'tiktok');
    }

    private function currentUserId(): int
    {
        $userId = Auth::id();
        abort_unless(is_int($userId), 403);

        return $userId;
    }

    private function resetForm(): void
    {
        $this->reset(['editingAccountId', 'showTiktokModal', 'name', 'login_email', 'login_password', 'is_active']);
        $this->resetValidation();
    }

    public function render(): View
    {
        $accounts = $this->accountQuery()->latest()->get();
        $youtubeAccount = $accounts->firstWhere('platform', 'youtube');

        return view('livewire.accounts.index', [
            'tiktokAccounts' => $this->decorateTiktokAccounts($accounts->where('platform', 'tiktok')),
            'youtubeAccount' => $youtubeAccount,
            'youtubeStatus' => $youtubeAccount instanceof SocialAccount
                ? [
                    'expired' => $youtubeAccount->tokenExpired(),
                    'label' => $youtubeAccount->tokenExpired() ? 'Token expirado' : 'Vinculado',
                ]
                : null,
            'tiktokModalTitle' => $this->editingAccountId !== null ? 'Editar conta TikTok' : 'Nova conta TikTok',
            'googleOAuthReady' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
        ]);
    }
}
