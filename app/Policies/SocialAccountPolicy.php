<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;

final class SocialAccountPolicy
{
    public function update(User $user, SocialAccount $account): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $account->user_id === $user->id;
    }

    public function delete(User $user, SocialAccount $account): bool
    {
        return $this->update($user, $account);
    }
}
