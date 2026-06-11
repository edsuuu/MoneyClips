<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

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
 * Conecta contas das plataformas guardando tokens criptografados.
 * YouTube e TikTok entram via OAuth; Meta cobre Facebook/Instagram.
 */
final class Accounts extends Component
{
    public ?string $managingPlatform = null;

    public ?int $editingAccountId = null;

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

    public function manage(string $platform): void
    {
        if (! in_array($platform, SocialAccount::PLATFORMS, true)) {
            Flux::toast('Plataforma inválida.', variant: 'danger');

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
        $this->name = $account?->name ?? '';
        $this->external_account_id = $account?->external_account_id ?? '';
        $this->access_token = $account?->access_token ?? '';
        $this->refresh_token = $account?->refresh_token ?? '';
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
            Flux::toast('Conta atualizada.');
        } else {
            SocialAccount::query()->create($payload);
            Flux::toast('Conta conectada.');
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

        Flux::toast('Conta desvinculada.');
    }

    public function render(SocialPublisherRegistry $registry): View
    {
        $accounts = SocialAccount::query()
            ->where('user_id', $this->currentUserId())
            ->latest()
            ->get();

        $accountsByPlatform = $accounts->groupBy('platform');

        $providers = collect($registry->labels())
            ->map(function (string $label, string $platform) use ($accountsByPlatform): array {
                $account = $accountsByPlatform->get($platform)?->first();
                $linked = $account instanceof SocialAccount;
                $oauthPlatform = in_array($platform, ['youtube', 'tiktok'], true);

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
            'platformLabels' => $registry->labels(),
            'providers' => $providers,
            'googleOAuthReady' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            'tiktokOAuthReady' => filled(config('services.tiktok.client_key')) && filled(config('services.tiktok.client_secret')) && filled(config('services.tiktok.redirect')),
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
            'tiktok' => 'TikTok Login Kit para captar open_id, access_token e refresh_token automaticamente.',
            'instagram' => 'OAuth Meta para publicar reels e conteudo no Instagram.',
            'facebook' => 'OAuth Meta para publicar no Facebook.',
            default => 'Conecte a conta para liberar a publicacao automatica.',
        };
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'external_account_id', 'access_token', 'refresh_token', 'token_expires_at', 'meta']);
    }
}
