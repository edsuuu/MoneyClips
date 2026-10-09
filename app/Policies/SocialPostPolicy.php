<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SocialPost;
use App\Models\User;

final class SocialPostPolicy
{
    public function update(User $user, SocialPost $post): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $post->socialAccount->user_id === $user->id;
    }
}
