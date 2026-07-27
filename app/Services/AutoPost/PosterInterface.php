<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

interface PosterInterface
{
    public function platform(): string;

    public function post(PostTaskData $task): PosterResultData;
}
