<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\PostProviderEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\TikTokPostWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Posting\PostCloserService;
use App\Services\Posting\PostResultData;

/**
 * Desfecho do TikTokUploader. A conta é sincronizada mesmo quando o post já
 * foi fechado (reaper/dono): cookies renovados e sessão expirada valem pros
 * próximos posts.
 */
final class TikTokPostWebhookController extends Controller
{
    public function __invoke(TikTokPostWebhookRequest $request, PostCloserService $closer): StatusResource
    {
        $post = SocialPost::query()
            ->with(['youtubeShort', 'socialAccount'])
            ->where('external_id', $request->jobId())
            ->whereRelation('socialAccount', 'provider', PostProviderEnum::TiktokUploader->value)
            ->first();

        if (! $post instanceof SocialPost) {
            return new StatusResource('unknown-job', 404);
        }

        $this->syncAccount($post->socialAccount, $request);

        $closed = $closer->close($post, match ($request->status()) {
            'completed' => PostResultData::published(null, 'public'),
            'dry-run' => PostResultData::failed('TikTokUploader em DRY_RUN: nada foi publicado.'),
            'restricted' => PostResultData::failed('TikTok restringiu o post: '.$request->reason()),
            default => PostResultData::failed($request->sessionStatus() === 'invalid'
                ? sprintf('Sessão do TikTok expirada: cole novos cookies em /contas. (%s)', $request->reason())
                : 'TikTokUploader falhou: '.$request->reason()),
        });

        return new StatusResource($closed ? 'post-closed' : 'already-finished');
    }

    private function syncAccount(SocialAccount $account, TikTokPostWebhookRequest $request): void
    {
        $cookies = $request->refreshedCookies();
        if ($cookies !== []) {
            $account->cookies = $cookies;
            $account->cookies_last_validated_at = now();
        }

        $account->session_status = match ($request->sessionStatus()) {
            'valid' => SocialAccount::SESSION_VALID,
            'invalid' => SocialAccount::SESSION_INVALID,
            default => $account->session_status,
        };

        $account->save();
    }
}
