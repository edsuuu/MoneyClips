<?php

declare(strict_types=1);

namespace App\Livewire\Accounts;

use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Tela dedicada de contas TikTok. Sem OAuth oficial, guardamos as credenciais
 * (email/senha) pra futuramente disparar o login automático no uploader.
 *
 * ponytail: a senha é gravada em texto puro (login_password sem cast). Upgrade
 * path: castar como 'encrypted' no SocialAccount e re-salvar as rows.
 */
final class Index extends Component
{
    use WithToasts;

    public ?int $editingAccountId = null;

    public bool $showForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $login_email = '';

    #[Validate('required|string|max:255')]
    public string $login_password = '';

    public bool $is_active = true;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $account = $this->accountQuery()->whereKey($id)->first();

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
        $this->showForm = true;
    }

    public function save(): void
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
            $account = $this->accountQuery()->whereKey($this->editingAccountId)->first();

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

    public function delete(int $id): void
    {
        $this->accountQuery()->whereKey($id)->delete();

        if ($this->editingAccountId === $id) {
            $this->resetForm();
        }

        $this->toast('Conta removida.');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.accounts.index', [
            'accounts' => $this->accountQuery()->latest()->get(),
        ]);
    }

    /**
     * @return Builder<SocialAccount>
     */
    private function accountQuery(): Builder
    {
        return SocialAccount::query()
            ->where('user_id', $this->currentUserId())
            ->where('platform', 'tiktok');
    }

    private function currentUserId(): int
    {
        $userId = Auth::id();
        abort_unless(is_int($userId), 403);

        return $userId;
    }

    private function resetForm(): void
    {
        $this->reset(['editingAccountId', 'showForm', 'name', 'login_email', 'login_password', 'is_active']);
        $this->resetValidation();
    }
}
