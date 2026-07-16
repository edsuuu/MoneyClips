<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

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
final readonly class FacebookReelsPoster implements PosterContract
{
    public function platform(): string
    {
        return 'facebook';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTask $task): PosterResult
    {
        Log::warning('[AutoPost][FacebookReels] Poster ainda não implementado.', ['short_id' => $task->short->id]);

        return PosterResult::failed($this->platform(), 'Poster não implementado.');
    }
}
