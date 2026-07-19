<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Confirm password')]
final class ConfirmPassword extends Component
{
    public string $password = '';

    public function mount(): void
    {
        if (session()->get('auth.authenticated_via_google') !== true) {
            return;
        }

        session()->put('auth.password_confirmed_at', time());

        $this->redirectIntended(default: route('dashboard.index', absolute: false), navigate: true);
    }

    public function confirmPassword(): void
    {
        $this->validate();

        /** @var User $user */
        $user = Auth::user();

        if (! Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session()->put('auth.password_confirmed_at', time());

        $this->redirectIntended(default: route('dashboard.index', absolute: false), navigate: true);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => 'Informe sua senha.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return ['password' => 'senha'];
    }

    public function render(): View
    {
        return view('livewire.auth.confirm-password');
    }
}
