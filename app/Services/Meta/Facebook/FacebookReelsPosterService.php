<?php

declare(strict_types=1);

namespace App\Services\Meta\Facebook;

use App\Contracts\PosterInterface;
use App\DataTransferObjects\PosterResultData;
use App\DataTransferObjects\PostTaskData;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Log;

/**
 * Stub do poster de Facebook Reels (Meta Graph API).
 *
 * Implementação futura: POST /{page-id}/video_reels (upload_phase=start →
 * upload binário → finish com description). Exige Página + token de Página
 * com publish_video (social_accounts, platform=facebook — mesmo app Meta do
 * Instagram). Habilite em platform_settings quando implementado.
 */
final readonly class FacebookReelsPosterService implements PosterInterface
{
    public function platform(): string
    {
        return 'facebook';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTaskData $task): PosterResultData
    {
        Log::warning('[AutoPost][FacebookReels] Poster ainda não implementado.', ['short_id' => $task->short->id]);

        return PosterResultData::failed($this->platform(), 'Poster não implementado.');
    }
}
