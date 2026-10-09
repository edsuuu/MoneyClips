<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Models\SocialPost;

interface PostProviderInterface
{
    public function post(SocialPost $post, string $localPath): PostResultData;
}
