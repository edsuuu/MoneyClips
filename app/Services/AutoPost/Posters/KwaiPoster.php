<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Log;

/**
 * Stub do poster do Kwai (Kwai Open API / open.kwai.com).
 *
 * Implementação futura: OAuth2 (social_accounts, platform=kwai) + endpoint
 * de video upload/publish da Open Platform. A API pública é limitada por
 * região — validar disponibilidade pro Brasil antes de investir.
 * Habilite em platform_settings quando implementado.
 */
final readonly class KwaiPoster implements PosterContract
{
    public function platform(): string
    {
        return 'kwai';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTask $task): PosterResult
    {
        Log::warning('[AutoPost][Kwai] Poster ainda não implementado.', ['short_id' => $task->short->id]);

        return PosterResult::failed($this->platform(), 'Poster não implementado.');
    }
}
