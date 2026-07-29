<?php

declare(strict_types=1);

namespace App\Services\API\Meta\Facebook;

use App\Services\AutoPost\PosterInterface;
use App\Services\AutoPost\PosterResultData;
use App\Services\AutoPost\PostTaskData;
use Illuminate\Support\Facades\Log;

final readonly class FacebookReelsPosterService implements PosterInterface
{
    public function platform(): string
    {
        return 'facebook';
    }

    public function post(PostTaskData $task): PosterResultData
    {
        Log::channel('daily')->warning('[WARN][AutoPost][FacebookReels] Poster ainda não implementado.', ['short_id' => $task->short->id]);

        return PosterResultData::failed($this->platform(), 'Poster não implementado.');
    }
}
