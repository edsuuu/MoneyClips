<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * Conecta contas das plataformas guardando tokens criptografados.
 * Hoje só YouTube (Google OAuth).
 */
final class Accounts extends Component
{
    use WithToasts;

    public ?string $managingPlatform = null;

    public ?int $editingAccountId = null;

    public string $platform = 'youtube';

    public string $name = '';

    public string $external_account_id = '';

    public string $access_token = '';

    public string $refresh_token = '';

    public string $token_expires_at = '';

    /** JSON livre com extras por plataforma (ig_user_id, page_id, privacy_level...). */
    public string $meta = '';

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'access_token' => ['required', 'string'],
            'token_expires_at' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.required' => 'Selecione a plataforma.',
            'name.required' => 'Informe o nome da conta.',
            'access_token.required' => 'Informe o access token.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'platform' => 'plataforma',
            'name' => 'nome da conta',
            'access_token' => 'access token',
            'token_expires_at' => 'data de expiração',
        ];
    }

    public function manage(string $platform): void
    {
        if (! in_array($platform, SocialAccount::PLATFORMS, true)) {
            $this->toast('Plataforma inválida.', 'danger');

            return;
        }

        $this->resetValidation();
        $this->platform = $platform;
        $this->managingPlatform = $platform;

        $account = SocialAccount::query()
            ->where('user_id', $this->currentUserId())
            ->where('platform', $platform)
            ->latest()
            ->first();

        $this->editingAccountId = $account?->id;
        $this->name = $account->name ?? '';
        $this->external_account_id = $account->external_account_id ?? '';
        $this->access_token = $account->access_token ?? '';
        $this->refresh_token = $account->refresh_token ?? '';
        $this->token_expires_at = $account?->token_expires_at?->format('Y-m-d\TH:i') ?? '';
        $this->meta = is_array($account?->meta) && $account->meta !== []
            ? (string) json_encode($account->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    public function cancelManage(): void
    {
        $this->resetForm();
        $this->managingPlatform = null;
        $this->editingAccountId = null;
    }

    public function save(): void
    {
        $this->validate();

        if (! in_array($this->platform, SocialAccount::PLATFORMS, true)) {
            $this->toast('Plataforma inválida.', 'danger');

            return;
        }

        $meta = null;
        if (mb_trim($this->meta) !== '') {
            $decoded = json_decode($this->meta, true);
            if (! is_array($decoded)) {
                $this->toast('O campo Meta precisa ser um JSON válido.', 'danger');

                return;
            }

            $meta = $decoded;
        }

        $ownerId = $this->currentUserId();
        $tokenExpiresAt = $this->parseTokenExpiresAt();

        if ($this->token_expires_at !== '' && ! $tokenExpiresAt instanceof CarbonInterface) {
            $this->toast('Informe uma data de expiração válida.', 'danger');

            return;
        }

        $account = SocialAccount::query()
            ->where('user_id', $ownerId)
            ->when(
                $this->editingAccountId !== null,
                fn ($query) => $query->whereKey($this->editingAccountId),
                fn ($query) => $query->where('platform', $this->platform),
            )
            ->latest()
            ->first();

        $payload = [
            'user_id' => $ownerId,
            'platform' => $this->platform,
            'name' => $this->name,
            'external_account_id' => $this->external_account_id ?: null,
            'access_token' => $this->access_token,
            'refresh_token' => $this->refresh_token ?: null,
            'token_expires_at' => $tokenExpiresAt,
            'meta' => $meta,
            'is_active' => true,
        ];

        if ($account instanceof SocialAccount) {
            $account->update($payload);
            $this->toast('Conta atualizada.');
        } else {
            SocialAccount::query()->create($payload);
            $this->toast('Conta conectada.');
        }

        $this->cancelManage();
    }

    public function disconnect(string $platform): void
    {
        SocialAccount::query()
            ->where('user_id', $this->currentUserId())
            ->where('platform', $platform)
            ->delete();

        if ($this->managingPlatform === $platform) {
            $this->cancelManage();
        }

        $this->toast('Conta desvinculada.');
    }

    public function render(): View
    {
        $accounts = SocialAccount::query()
            ->where('user_id', $this->currentUserId())
            ->latest()
            ->get();

        $accountsByPlatform = $accounts->groupBy('platform');

        // Hoje só o YouTube tem publisher OAuth — TikTok usa cookie session.
        $platformLabels = ['youtube' => 'YouTube'];

        $providers = collect($platformLabels)
            ->map(function (string $label, string $platform) use ($accountsByPlatform): array {
                $account = $accountsByPlatform->get($platform)?->first();
                $linked = $account instanceof SocialAccount;
                $oauthPlatform = $platform === 'youtube';

                return [
                    'key' => $platform,
                    'label' => $label,
                    'badge' => mb_strtoupper(mb_substr($label, 0, min(2, mb_strlen($label)))),
                    'description' => $this->platformDescription($platform),
                    'account' => $account,
                    'channelUrl' => $platform === 'youtube' && filled($account?->external_account_id)
                        ? 'https://www.youtube.com/channel/'.$account->external_account_id
                        : null,
                    'isLinked' => $linked,
                    'status' => $linked ? ($account->tokenExpired() ? 'Token expirado' : 'Vinculado') : ('Nao vinculado'),
                    'statusColor' => $linked ? ($account->tokenExpired() ? 'amber' : 'green') : ('zinc'),
                    'actionLabel' => $linked ? 'Gerenciar' : 'Vincular',
                    'usesOauth' => $oauthPlatform,
                ];
            })
            ->values();

        return view('livewire.settings.accounts', [
            'platformLabels' => $platformLabels,
            'providers' => $providers,
            'googleOAuthReady' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
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

    private function platformDescription(string $platform): string
    {
        return match ($platform) {
            'youtube' => 'Google OAuth para conectar o canal e publicar no YouTube.',
            default => 'Conecte a conta para liberar a publicacao automatica.',
        };
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'external_account_id', 'access_token', 'refresh_token', 'token_expires_at', 'meta']);
    }
}
