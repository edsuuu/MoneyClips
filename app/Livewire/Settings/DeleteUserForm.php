<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    public function deleteUser(Logout $logout): void
    {
        $this->validate();

        /** @var User $user */
        $user = Auth::user();

        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => 'Informe sua senha para confirmar a exclusão.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return ['password' => 'senha'];
    }

    public function render(): View
    {
        return view('livewire.settings.delete-user-form');
    }
}
