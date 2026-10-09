<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\PostStatusEnum;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostSchedulerService
{
    private const int LEAD_MINUTES = 5;

    /**
     * Primeiro horário de `posting.times` depois de agora + 5 min, a pelo
     * menos `min_gap_minutes` de qualquer post ativo da conta e com o dia
     * abaixo de `per_day`. Cada post ativo bloqueia no máximo 2 dias (o gap
     * pode passar da meia-noite), então em `2 × count + 2` dias sempre há um
     * dia inteiro livre: a busca termina sem laço infinito.
     *
     * @throws RuntimeException
     */
    public function nextSlot(SocialAccount $account): CarbonImmutable
    {
        $earliest = CarbonImmutable::now()->addMinutes(self::LEAD_MINUTES);
        $perDay = (int) config('posting.per_day');
        $minGap = (int) config('posting.min_gap_minutes');

        /** @var list<string> $times */
        $times = config('posting.times');

        $taken = SocialPost::query()
            ->where('social_account_id', $account->id)
            ->whereIn('status', [PostStatusEnum::Scheduled, PostStatusEnum::Posting, PostStatusEnum::Published])
            ->where('scheduled_for', '>=', $earliest->startOfDay()->subMinutes($minGap))
            ->get()
            ->map(fn (SocialPost $post): CarbonImmutable => $post->scheduled_for);

        for ($day = 0; $day <= 2 * $taken->count() + 1; $day++) {
            $date = $earliest->startOfDay()->addDays($day);
            $sameDay = $taken->filter(fn (CarbonImmutable $at): bool => $at->isSameDay($date));
            if ($sameDay->count() >= $perDay) {
                continue;
            }

            $slots = array_map($date->setTimeFromTimeString(...), $times);
            sort($slots);

            foreach ($slots as $slot) {
                if ($slot->lessThanOrEqualTo($earliest)) {
                    continue;
                }

                if ($taken->contains(fn (CarbonImmutable $at): bool => $at->diffInMinutes($slot, true) < $minGap)) {
                    continue;
                }

                return $slot;
            }
        }

        throw new RuntimeException('Sem horário pra agendar: confira posting.times, posting.per_day e posting.min_gap_minutes.');
    }

    /**
     * Agenda o Short na conta (sem `$at` = próximo horário bom). Devolve null
     * quando o Short já tem post ativo nessa conta: o unique Short × conta não
     * deixa duplicar, e a linha Cancelada é reaproveitada. A conta fica
     * travada até gravar, senão dois agendamentos pegam o mesmo horário.
     *
     * @throws RuntimeException
     */
    public function schedule(YoutubeShort $short, SocialAccount $account, ?CarbonImmutable $at = null): ?SocialPost
    {
        return DB::transaction(function () use ($short, $account, $at): ?SocialPost {
            SocialAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            $post = SocialPost::query()->firstOrNew(['youtube_short_id' => $short->id, 'social_account_id' => $account->id]);
            if ($post->exists && $post->status !== PostStatusEnum::Canceled) {
                return null;
            }

            $post->fill([
                'scheduled_for' => $at ?? $this->nextSlot($account),
                'status' => PostStatusEnum::Scheduled,
                'privacy' => null,
                'external_id' => null,
                'url' => null,
                'error' => null,
                'started_at' => null,
                'posted_at' => null,
            ])->save();

            return $post;
        });
    }

    /**
     * Short pronto entra sozinho no próximo horário livre de cada conta
     * Automática do dono. Conta que já tem QUALQUER linha desse Short fica de
     * fora — inclusive Cancelada: o dono cancelou, o preenchimento não desfaz.
     * Short de canal (sem dono) nunca entra sozinho.
     *
     * @return list<SocialPost>
     *
     * @throws RuntimeException
     */
    public function autoSchedule(YoutubeShort $short): array
    {
        if (is_null($short->user_id) || is_null($short->ready_at) || ! is_null($short->posted_youtube_at) || ! is_null($short->posted_tiktok_at)) {
            return [];
        }

        $accounts = $this->autoAccounts()
            ->where('user_id', $short->user_id)
            ->whereDoesntHave('socialPosts', fn (Builder $query): Builder => $query->where('youtube_short_id', $short->id))
            ->get();

        $posts = [];
        foreach ($accounts as $account) {
            $post = $this->schedule($short, $account);
            if ($post instanceof SocialPost) {
                $posts[] = $post->setRelation('socialAccount', $account);
            }
        }

        return $posts;
    }

    /**
     * O que faltou agendar nas contas Automáticas (conta virou Automática
     * depois do Short ficar pronto, horário que não coube etc.), do pronto
     * mais antigo pro mais novo.
     *
     * @throws RuntimeException
     */
    public function fillAutoAccounts(): int
    {
        $shorts = YoutubeShort::query()
            ->whereIn('user_id', $this->autoAccounts()->select('user_id'))
            ->whereNotNull('video_path')
            ->whereNotNull('ready_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at')
            ->oldest('ready_at')
            ->get();

        return $shorts->sum(fn (YoutubeShort $short): int => count($this->autoSchedule($short)));
    }

    /** @return Builder<SocialAccount> */
    public function autoAccounts(): Builder
    {
        return SocialAccount::query()
            ->where('is_active', true)
            ->where('auto_schedule', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('session_status')->orWhere('session_status', '!=', SocialAccount::SESSION_INVALID));
    }

    /**
     * Tentar de novo / reagendar um Failed ou Missed. Devolve null se o post
     * saiu desses estados no meio do caminho (o UPDATE só vinga neles).
     *
     * @throws RuntimeException
     */
    public function reschedule(SocialPost $post, ?CarbonImmutable $at = null): ?CarbonImmutable
    {
        return DB::transaction(function () use ($post, $at): ?CarbonImmutable {
            SocialAccount::query()->whereKey($post->social_account_id)->lockForUpdate()->first();

            $at ??= $this->nextSlot($post->socialAccount);
            $updated = SocialPost::query()
                ->whereKey($post->id)
                ->whereIn('status', [PostStatusEnum::Failed, PostStatusEnum::Missed])
                ->update(['status' => PostStatusEnum::Scheduled, 'scheduled_for' => $at, 'error' => null, 'started_at' => null]);

            return $updated === 1 ? $at : null;
        });
    }

    /**
     * Só Missed: Failed pode ter causa que o dono precisa ler antes.
     *
     * @throws RuntimeException
     */
    public function rescheduleMissed(User $user): int
    {
        $missed = SocialPost::query()
            ->forUser($user)
            ->with('socialAccount')
            ->where('status', PostStatusEnum::Missed)
            ->oldest('scheduled_for')
            ->get();

        return $missed->filter(fn (SocialPost $post): bool => $this->reschedule($post) instanceof CarbonImmutable)->count();
    }
}
