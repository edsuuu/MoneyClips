<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use App\Livewire\Concerns\WithToasts;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile settings')]
final class Profile extends Component
{
    use ProfileValidationRules;
    use WithToasts;

    public string $name = '';

    public string $email = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updateProfileInformation(): void
    {
        /** @var User $user */
        $user = Auth::user();

        /** @var array<string, mixed> $validated */
        $validated = $this->validate(['name' => $this->nameRules()]);

        $user->fill($validated);
        $user->save();

        $this->email = $user->email;

        $this->toast(__('Profile updated.'));
    }

    public function resendVerificationNotification(): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard.index', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        $this->toast(__('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    public function render(): View
    {
        return view('livewire.settings.profile');
    }
}
