<?php

declare(strict_types=1);

namespace App\Services\API\Kwai;

use App\Models\PlatformSetting;
use App\Services\AutoPost\PosterInterface;
use App\Services\AutoPost\PosterResultData;
use App\Services\AutoPost\PostTaskData;
use Illuminate\Support\Facades\Log;

final readonly class KwaiPosterService implements PosterInterface
{
    public function platform(): string
    {
        return 'kwai';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTaskData $task): PosterResultData
    {
        Log::channel('daily')->warning('[WARN][AutoPost][Kwai] Poster ainda não implementado.', ['short_id' => $task->short->id]);

        return PosterResultData::failed($this->platform(), 'Poster não implementado.');
    }
}
