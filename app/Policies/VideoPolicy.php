<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

/**
 * A biblioteca é compartilhada entre os operadores: qualquer autenticado
 * assiste, só o dono mexe.
 */
final class VideoPolicy
{
    public function view(): bool
    {
        return true;
    }

    public function update(User $user, Video $video): bool
    {
        return $video->user_id === $user->id;
    }

    public function delete(User $user, Video $video): bool
    {
        return $video->user_id === $user->id;
    }
}
