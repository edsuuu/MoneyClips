<?php

declare(strict_types=1);

namespace App\Livewire\Accounts;

use App\Enums\PostProviderEnum;
use App\Livewire\Concerns\WithCurrentUser;
use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

final class Index extends Component
{
    use WithCurrentUser;
    use WithToasts;

    public bool $showTiktokModal = false;

    public bool $showYoutubeModal = false;

    public ?int $editingAccountId = null;

    public string $name = '';

    public string $cookiesInput = '';

    public bool $is_active = true;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cookiesInput' => [
                $this->editingAccountId === null ? 'required' : 'nullable',
                'json',
                'max:200000',
                fn (string $attribute, mixed $value, Closure $fail) => is_array(json_decode((string) $value, true)) || $value === '' ? null : $fail('Os cookies precisam ser uma lista JSON.'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome ou @handle da conta.',
            'cookiesInput.required' => 'Cole os cookies da sessão.',
            'cookiesInput.json' => 'Os cookies precisam estar em JSON válido.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'name' => 'nome',
            'cookiesInput' => 'cookies',
        ];
    }

    public function createTiktok(): void
    {
        $this->resetForm();
        $this->showTiktokModal = true;
    }

    public function editTiktok(int $id): void
    {
        $account = $this->tiktokQuery()->find($id);

        if (! $account instanceof SocialAccount) {
            $this->toast('Conta não encontrada.', 'danger');

            return;
        }

        $this->authorize('update', $account);
        if ($account->provider === PostProviderEnum::TiktokOfficial) {
            $this->toast('Conta oficial não usa cookies: revincule pelo botão TikTok oficial.', 'danger');

            return;
        }

        $this->resetValidation();
        $this->editingAccountId = $account->id;
        $this->name = $account->name;
        $this->is_active = $account->is_active;
        $this->showTiktokModal = true;
    }

    public function saveTiktok(): void
    {
        $this->validate();

        $payload = [
            'user_id' => $this->currentUser()->id,
            'platform' => 'tiktok',
            'name' => $this->name,
            'is_active' => $this->is_active,
        ];

        if ($this->cookiesInput !== '') {
            $payload['cookies'] = json_decode($this->cookiesInput, true, 512, JSON_THROW_ON_ERROR);
            $payload['cookies_last_validated_at'] = now();
        }

        if ($this->editingAccountId !== null) {
            $account = $this->tiktokQuery()->find($this->editingAccountId);

            if (! $account instanceof SocialAccount) {
                $this->toast('Conta não encontrada.', 'danger');

                return;
            }

            $this->authorize('update', $account);
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
        $account = SocialAccount::query()->find($id);
        if (! $account instanceof SocialAccount) {
            return;
        }

        $this->authorize('update', $account);
        $account->is_active = ! $account->is_active;
        $account->save();
        $this->toast(sprintf('Conta %s.', $account->is_active ? 'ativada' : 'desativada'));
    }

    public function delete(int $id): void
    {
        $account = SocialAccount::query()->find($id);
        if (! $account instanceof SocialAccount) {
            return;
        }

        $this->authorize('delete', $account);
        $account->delete();

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
     * @return Collection<int, array{id: int, name: string, is_active: bool, isOfficial: bool, statusColor: string, statusLabel: string, subtitle: string, sessionSavedLabel: string}>
     */
    private function decorateTiktokAccounts(Collection $accounts): Collection
    {
        return $accounts
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'is_active' => $account->is_active,
                'isOfficial' => $account->provider === PostProviderEnum::TiktokOfficial,
                'statusColor' => $this->sessionStatusColor($account->session_status),
                'statusLabel' => $this->sessionStatusLabel($account->session_status),
                'subtitle' => $account->name,
                'sessionSavedLabel' => match (true) {
                    $account->provider === PostProviderEnum::TiktokOfficial => 'API oficial (Login Kit)',
                    $account->cookies_last_validated_at === null => 'Sem sessão salva',
                    default => 'Sessão salva em '.$account->cookies_last_validated_at->format('d/m/Y H:i'),
                },
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
        return SocialAccount::query()->forUser($this->currentUser());
    }

    /**
     * @return Builder<SocialAccount>
     */
    private function tiktokQuery(): Builder
    {
        return SocialAccount::query()->where('platform', 'tiktok');
    }

    private function resetForm(): void
    {
        $this->reset(['editingAccountId', 'showTiktokModal', 'name', 'cookiesInput', 'is_active']);
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
                    'expired' => $youtubeAccount->session_status === SocialAccount::SESSION_INVALID,
                    'label' => $youtubeAccount->session_status === SocialAccount::SESSION_INVALID ? 'Acesso revogado' : 'Vinculado',
                ]
                : null,
            'cookiesHint' => $this->editingAccountId !== null ? 'Deixe em branco para manter a sessão salva.' : null,
            'tiktokModalTitle' => $this->editingAccountId !== null ? 'Editar conta TikTok' : 'Nova conta TikTok',
            'googleOAuthReady' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
        ]);
    }
}
