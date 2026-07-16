<?php

declare(strict_types=1);

namespace App\Services\Api\Meta\Facebook;

use App\Models\PlatformSetting;
use App\Services\AutoPost\PosterInterface;
use App\Services\AutoPost\PosterResultData;
use App\Services\AutoPost\PostTaskData;
use Illuminate\Support\Facades\Log;

/**
 * Stub do poster de Facebook Reels (Meta Graph API).
 *
 * Implementação futura: POST /{page-id}/video_reels (upload_phase=start →
 * upload binário → finish com description). Exige Página + token de Página
 * com publish_video (social_accounts, platform=facebook — mesmo app Meta do
 * Instagram — META_APP_ID/META_APP_SECRET no .env, config/services.php → meta).
 * Habilite em platform_settings quando implementado.
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
