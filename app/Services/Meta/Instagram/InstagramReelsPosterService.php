<?php

declare(strict_types=1);

namespace App\Services\Meta\Instagram;

use App\Contracts\PosterInterface;
use App\DataTransferObjects\PosterResultData;
use App\DataTransferObjects\PostTaskData;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Log;

/**
 * Stub do poster de Instagram Reels (Meta Graph API).
 *
 * Implementação futura: POST /{ig-user-id}/media {media_type: REELS,
 * video_url, caption} → poll do status_code → POST /{ig-user-id}/media_publish.
 * Exige conta IG Business/Creator ligada a uma Página + token com
 * instagram_content_publish (social_accounts, platform=instagram).
 * A Graph API só aceita video_url público — servir via URL pré-assinada do
 * MinIO ou proxy no Laravel. Habilite em platform_settings quando implementado.
 */
final readonly class InstagramReelsPosterService implements PosterInterface
{
    public function platform(): string
    {
        return 'instagram';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTaskData $task): PosterResultData
    {
        Log::warning('[AutoPost][InstagramReels] Poster ainda não implementado.', ['short_id' => $task->short->id]);

        return PosterResultData::failed($this->platform(), 'Poster não implementado.');
    }
}
