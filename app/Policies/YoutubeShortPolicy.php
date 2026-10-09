<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\YoutubeShort;

final class YoutubeShortPolicy
{
    public function update(User $user, YoutubeShort $short): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $short->user_id === $user->id;
    }
}
