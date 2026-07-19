<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Forgot password')]
final class ForgotPassword extends Component
{
    public string $email = '';

    public function sendPasswordResetLink(): void
    {
        $this->validate();

        $status = Password::broker()->sendResetLink(
            ['email' => $this->email]
        );

        if ($status !== Password::RESET_LINK_SENT) {
            $this->addError('email', __($status));

            return;
        }

        session()->flash('status', __($status));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return ['email' => 'e-mail'];
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}
