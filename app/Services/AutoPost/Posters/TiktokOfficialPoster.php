<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Log;

/**
 * Stub do poster oficial do TikTok (Content Posting API).
 *
 * Implementação futura: POST https://open.tiktokapis.com/v2/post/publish/video/init/
 * (modo FILE_UPLOAD ou PULL_FROM_URL) com OAuth2 da conta em social_accounts
 * (platform=tiktok_official; access_token/refresh_token já são colunas
 * criptografadas). O app TikTok for Developers já está pré-configurado com
 * credenciais — client_key/client_secret entram em config/services.php quando
 * a integração for ligada. Habilite em platform_settings quando implementado.
 */
final readonly class TiktokOfficialPoster implements PosterContract
{
    public function platform(): string
    {
        return 'tiktok_official';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTask $task): PosterResult
    {
        Log::warning('[AutoPost][TikTokOficial] Poster ainda não implementado.', ['short_id' => $task->short->id]);

        return PosterResult::failed($this->platform(), 'Poster não implementado.');
    }
}
