<?php

declare(strict_types=1);

namespace App\Services\TikTokUploader;

use App\Models\PlatformSetting;
use App\Models\SocialAccount;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\AutoPost\PosterInterface;
use App\Services\AutoPost\PosterResultData;
use App\Services\AutoPost\PostTaskData;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TiktokPosterService implements PosterInterface
{
    public function __construct(
        private TiktokUploaderService $uploader,
        private DiscordNotifierService $discord,
    ) {}

    public function platform(): string
    {
        return 'tiktok';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTaskData $task): PosterResultData
    {
        $account = SocialAccount::query()
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $account instanceof SocialAccount) {
            return PosterResultData::failed($this->platform(), 'Nenhuma conta TikTok ativa cadastrada em /contas.');
        }

        if ($account->session_status === SocialAccount::SESSION_INVALID) {
            Log::warning('[AutoPost][TikTok] Sessão inválida — pulando disparo.', ['short_id' => $task->short->id]);

            return PosterResultData::failed($this->platform(), 'Sessão inválida — renove os cookies em /contas.');
        }

        $cookies = is_array($account->cookies) ? $account->cookies : [];
        if ($cookies === []) {
            return PosterResultData::failed($this->platform(), 'Conta TikTok sem cookies salvos — importe em /contas.');
        }

        try {
            $jobId = $this->uploader->queuePost($task->videoPath, $cookies, $task->title, $task->hashtags, $account->id);
        } catch (Throwable $throwable) {
            Log::error('[AutoPost][TikTok] Falha ao enfileirar o post no uploader.', ['short_id' => $task->short->id, 'error' => $throwable->getMessage()]);
            $this->discord->error('❌ TikTok: uploader indisponível', ($task->title ?: $task->short->youtube_id).PHP_EOL.$throwable->getMessage());

            return PosterResultData::failed($this->platform(), $throwable->getMessage());
        }

        Log::info('[AutoPost][TikTok] Post enfileirado no uploader.', ['short_id' => $task->short->id, 'job_id' => $jobId]);

        return PosterResultData::queued($this->platform(), $jobId);
    }
}
