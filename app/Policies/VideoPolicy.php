<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

final class VideoPolicy
{
    public function view(User $user, Video $video): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $video->user_id === $user->id;
    }
}
