<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Concerns\WithToasts;
use App\Models\User;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Jenssegers\Agent\Agent;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Security settings')]
final class Security extends Component
{
    use PasswordValidationRules;
    use WithToasts;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public bool $hasPassword = true;

    #[Locked]
    public bool $canManageTwoFactor;

    #[Locked]
    public bool $twoFactorEnabled;

    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public ?string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showModal = false;

    public bool $showVerificationStep = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->hasPassword = (bool) $user->has_password;

        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null($user->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication($user);
            }

            $this->twoFactorEnabled = $user->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }
    }

    public function updatePassword(): void
    {
        $rules = ['password' => $this->passwordRules()];

        if ($this->hasPassword) {
            $rules['current_password'] = $this->currentPasswordRules();
        }

        try {
            /** @var array<string, mixed> $validated */
            $validated = $this->validate($rules);
        } catch (ValidationException $validationException) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $validationException;
        }

        /** @var User $user */
        $user = Auth::user();

        $user->update([
            'password' => $validated['password'],
            'has_password' => true,
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->toast($this->hasPassword ? __('Senha atualizada.') : __('Senha criada.'));

        $this->hasPassword = true;
    }

    public function logoutSession(string $id): void
    {
        if ($id === session()->getId()) {
            return;
        }

        DB::table($this->sessionsTable())
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->delete();

        $this->toast(__('Sessão encerrada.'));
    }

    public function logoutOtherSessions(): void
    {
        DB::table($this->sessionsTable())
            ->where('user_id', Auth::id())
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->toast(__('Outras sessões encerradas.'));
    }

    /**
     * Sessões ativas do usuário, prontas para exibição.
     *
     * ponytail: só funciona com SESSION_DRIVER=database (o padrão do projeto);
     * outros drivers não guardam sessão por usuário e a lista fica vazia.
     *
     * @return array<int, array{id: string, device: string, ip: string, last_active: string, is_current: bool}>
     */
    #[Computed]
    public function sessions(): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $agent = new Agent();
        $currentId = session()->getId();

        return DB::table($this->sessionsTable())
            ->where('user_id', Auth::id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $session) use ($agent, $currentId): array {
                $agent->setUserAgent((string) ($session->user_agent ?? ''));

                $platform = $agent->platform();
                $browser = $agent->browser();

                return [
                    'id' => (string) $session->id,
                    'device' => mb_trim(sprintf(
                        '%s em %s',
                        is_string($browser) && $browser !== '' ? $browser : __('Navegador desconhecido'),
                        is_string($platform) && $platform !== '' ? $platform : __('sistema desconhecido'),
                    )),
                    'ip' => (string) ($session->ip_address ?? '—'),
                    'last_active' => CarbonImmutable::createFromTimestamp((int) $session->last_activity)->diffForHumans(),
                    'is_current' => (string) $session->id === $currentId,
                ];
            })
            ->all();
    }

    public function enable(EnableTwoFactorAuthentication $enableTwoFactorAuthentication): void
    {
        /** @var User $user */
        $user = auth()->user();

        $enableTwoFactorAuthentication($user);

        if (! $this->requiresConfirmation) {
            $this->twoFactorEnabled = $user->hasEnabledTwoFactorAuthentication();
        }

        $this->loadSetupData();

        $this->showModal = true;
    }

    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
    }

    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        /** @var User $user */
        $user = auth()->user();

        $confirmTwoFactorAuthentication($user, $this->code);

        $this->closeModal();

        $this->twoFactorEnabled = true;
    }

    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        /** @var User $user */
        $user = auth()->user();

        $disableTwoFactorAuthentication($user);

        $this->twoFactorEnabled = false;
    }

    public function closeModal(): void
    {
        $this->reset(
            'code',
            'manualSetupKey',
            'qrCodeSvg',
            'showModal',
            'showVerificationStep',
        );

        $this->resetErrorBag();

        if (! $this->requiresConfirmation) {
            /** @var User $user */
            $user = auth()->user();

            $this->twoFactorEnabled = $user->hasEnabledTwoFactorAuthentication();
        }
    }

    /**
     * Get the current modal configuration state.
     *
     * @return array{title: string, description: string, buttonText: string}
     */
    #[Computed]
    public function modalConfig(): array
    {
        if ($this->twoFactorEnabled) {
            return [
                'title' => __('Two-factor authentication enabled'),
                'description' => __('Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.'),
                'buttonText' => __('Close'),
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => __('Verify authentication code'),
                'description' => __('Enter the 6-digit code from your authenticator app.'),
                'buttonText' => __('Continue'),
            ];
        }

        return [
            'title' => __('Enable two-factor authentication'),
            'description' => __('To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app.'),
            'buttonText' => __('Continue'),
        ];
    }

    private function sessionsTable(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function loadSetupData(): void
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
            $decrypted = decrypt((string) $user->two_factor_secret);
            $this->manualSetupKey = is_string($decrypted) ? $decrypted : '';
        } catch (Exception) {
            $this->addError('setupData', 'Failed to fetch setup data.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    public function render(): View
    {
        return view('livewire.settings.security');
    }
}
