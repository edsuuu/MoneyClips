<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SocialPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ledger genérico de postagens em redes sociais. A coluna `platform`
 * discrimina o destino (tiktok, youtube, instagram, ...). Cada postagem de
 * slot gera 1 linha por (schedule_slot_id, platform) — é a fonte do status
 * por plataforma que a /agenda mostra (posted / parcial / failed).
 *
 * Quem cria/atualiza essas linhas é o job PostSlotToPlatform (via Posters
 * de App\Services\AutoPost\Posters). Posts manuais ficam com slot null.
 *
 * @property int $id
 * @property string $platform
 * @property string $uuid
 * @property int|null $schedule_slot_id
 * @property string|null $youtube_id
 * @property string|null $video_key
 * @property string|null $title
 * @property array<int, string>|null $hashtags
 * @property string|null $account_name
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $posted_at
 * @property-read ScheduleSlot|null $scheduleSlot
 */
final class SocialPost extends Model
{
    /** @use HasFactory<SocialPostFactory> */
    use HasFactory;

    /** Status que bloqueiam novo post automático do mesmo Short. */
    public const array ACTIVE_STATUSES = ['queued', 'processing', 'completed', 'dry-run', 'restricted'];

    protected $fillable = [
        'platform', 'uuid', 'schedule_slot_id', 'youtube_id', 'video_key', 'title', 'hashtags',
        'account_name', 'status', 'error', 'requested_at', 'started_at', 'posted_at',
    ];

    /** @return BelongsTo<ScheduleSlot, $this> */
    public function scheduleSlot(): BelongsTo
    {
        return $this->belongsTo(ScheduleSlot::class);
    }

    /**
     * Posts enfileirados, em andamento ou concluídos — bloqueiam novo post
     * do mesmo Short (falhas liberam o vídeo para tentar de novo).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }
}
