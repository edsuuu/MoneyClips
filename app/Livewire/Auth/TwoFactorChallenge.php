<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Two-factor challenge')]
final class TwoFactorChallenge extends Component
{
    public string $recovery_code = '';

    public string $code = '';

    public function login(): void
    {
        $this->validate();

        if ($this->recovery_code !== '' && $this->recovery_code !== '0') {
            $this->loginWithRecoveryCode();
        } else {
            $this->loginWithTwoFactorCode();
        }
    }

    private function loginWithTwoFactorCode(): void
    {
        $request = resolve(TwoFactorLoginRequest::class);

        $request->merge(['code' => $this->code]);

        /** @var User $user */
        $user = $request->challengedUser();

        if ($request->hasValidCode()) {
            Auth::login($user, $request->remember());

            $request->session()->forget(['login.id', 'auth.authenticated_via_google']);

            $this->redirectIntended(default: route('dashboard.index', absolute: false), navigate: true);
        } else {
            event(new TwoFactorAuthenticationFailed($user));

            $this->addError('code', __('The provided two-factor authentication code was invalid.'));
        }
    }

    private function loginWithRecoveryCode(): void
    {
        $request = resolve(TwoFactorLoginRequest::class);

        $request->merge(['recovery_code' => $this->recovery_code]);

        /** @var User $user */
        $user = $request->challengedUser();

        if ($recoveryCode = $request->validRecoveryCode()) {
            $user->replaceRecoveryCode($recoveryCode);

            Auth::login($user, $request->remember());

            $request->session()->forget(['login.id', 'auth.authenticated_via_google']);

            $this->redirectIntended(default: route('dashboard.index', absolute: false), navigate: true);
        } else {
            event(new TwoFactorAuthenticationFailed($user));

            $this->addError('recovery_code', __('The provided recovery code was invalid.'));
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.string' => 'Informe um código válido.',
            'recovery_code.string' => 'Informe um código de recuperação válido.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'code' => 'código',
            'recovery_code' => 'código de recuperação',
        ];
    }

    public function render(): View
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
