<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\PostStatusEnum;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
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
}
