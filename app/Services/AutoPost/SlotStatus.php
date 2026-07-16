<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\PlatformSetting;
use App\Models\ScheduleSlot;
use Carbon\CarbonImmutable;

/**
 * Status de exibição de um slot da agenda — sempre COMPUTADO na leitura a
 * partir do slot + suas social_posts (nunca persistido, então nunca fica
 * dessincronizado).
 *
 * Antes do despacho: empty | paused | future | due | skipped.
 * Depois do despacho (agregado por plataforma): posting | posted | partial | failed.
 */
final class SlotStatus
{
    private const array SUCCESS_STATUSES = ['completed', 'dry-run'];

    private const array PENDING_STATUSES = ['queued', 'processing'];

    /**
     * @return array{
     *   status: string,
     *   platforms: list<array{platform: string, name: string, ok: bool, pending: bool, reason: string|null}>
     * }
     */
    public static function resolve(ScheduleSlot $slot, CarbonImmutable $now): array
    {
        if ($slot->dispatched_at !== null) {
            return self::dispatchedStatus($slot);
        }

        $nowSp = $now->timezone(AutoPost::TIMEZONE);
        $scheduledAt = $slot->scheduledAt();
        $isPast = $scheduledAt->lessThan($nowSp->subMinutes(AutoPostDispatcher::GRACE_MINUTES));

        if (! $slot->is_active) {
            return ['status' => $isPast ? 'skipped' : 'paused', 'platforms' => []];
        }

        if ($slot->youtube_short_id === null) {
            return ['status' => 'empty', 'platforms' => []];
        }

        if ($scheduledAt->greaterThan($nowSp)) {
            return ['status' => 'future', 'platforms' => []];
        }

        return ['status' => $isPast ? 'skipped' : 'due', 'platforms' => []];
    }

    /**
     * @return array{
     *   status: string,
     *   platforms: list<array{platform: string, name: string, ok: bool, pending: bool, reason: string|null}>
     * }
     */
    private static function dispatchedStatus(ScheduleSlot $slot): array
    {
        $posts = $slot->socialPosts;

        // Despachado mas os jobs ainda não criaram o ledger — está postando.
        if ($posts->isEmpty()) {
            return ['status' => 'posting', 'platforms' => []];
        }

        $platforms = [];
        $pending = 0;
        $succeeded = 0;

        foreach ($posts as $post) {
            $isPending = in_array($post->status, self::PENDING_STATUSES, true);
            $isSuccess = in_array($post->status, self::SUCCESS_STATUSES, true);

            $pending += $isPending ? 1 : 0;
            $succeeded += $isSuccess ? 1 : 0;

            $platforms[] = [
                'platform' => $post->platform,
                'name' => PlatformSetting::displayName($post->platform),
                'ok' => $isSuccess,
                'pending' => $isPending,
                'reason' => $post->error,
            ];
        }

        $status = match (true) {
            $pending > 0 => 'posting',
            $succeeded === count($platforms) => 'posted',
            $succeeded === 0 => 'failed',
            default => 'partial',
        };

        return ['status' => $status, 'platforms' => $platforms];
    }
}
