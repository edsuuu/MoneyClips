<?php

declare(strict_types=1);

namespace App\Services\API\TikTok;

use App\Models\PlatformSetting;
use App\Services\AutoPost\PosterInterface;
use App\Services\AutoPost\PosterResultData;
use App\Services\AutoPost\PostTaskData;
use Illuminate\Support\Facades\Log;

final readonly class TiktokOfficialPosterService implements PosterInterface
{
    public function platform(): string
    {
        return 'tiktok_official';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTaskData $task): PosterResultData
    {
        Log::channel('daily')->warning('[WARN][AutoPost][TikTokOficial] Poster ainda não implementado.', ['short_id' => $task->short->id]);

        return PosterResultData::failed($this->platform(), 'Poster não implementado.');
    }
}
