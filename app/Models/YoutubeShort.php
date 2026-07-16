<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\YoutubeShortFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um Short do YouTube baixado de um canal e armazenado no MinIO.
 *
 * Ciclo de vida do estoque: baixado (video_path + downloaded_at) →
 * opcionalmente processado (reencode/template → processed_video_path) →
 * pronto para postar (ready_at) → atribuído a um slot da agenda
 * (schedule_slots) → postado (posted_youtube_at / posted_tiktok_at +
 * social_posts por plataforma).
 *
 * @property int $id
 * @property string $youtube_id
 * @property string|null $channel_url
 * @property string|null $title
 * @property array<int, string>|null $hashtags
 * @property string|null $video_path
 * @property string|null $processed_video_path
 * @property string|null $youtube_video_id
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $template_rendered_at
 * @property Carbon|null $posted_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $posted_youtube_at
 * @property Carbon|null $posted_tiktok_at
 * @property-read Collection<int, ScheduleSlot> $scheduleSlots
 */
final class YoutubeShort extends Model
{
    /** @use HasFactory<YoutubeShortFactory> */
    use HasFactory;

    protected $fillable = [
        'youtube_id', 'channel_url', 'title', 'hashtags',
        'video_path', 'processed_video_path', 'youtube_video_id',
        'downloaded_at', 'ready_at', 'template_rendered_at', 'posted_at',
        'dispatched_at', 'posted_youtube_at', 'posted_tiktok_at',
    ];

    /** @return HasMany<ScheduleSlot, $this> */
    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class);
    }

    /** O que os posters publicam: a saída processada quando existir, senão o original. */
    public function postableVideoPath(): string
    {
        return (string) ($this->processed_video_path ?? $this->video_path);
    }

    /**
     * Prontos para entrar na agenda: com vídeo, marcados como prontos, ainda
     * não postados e sem slot pendente segurando o vídeo.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeReadyToSchedule(Builder $query): Builder
    {
        return $query
            ->whereNotNull('video_path')
            ->whereNotNull('ready_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at')
            ->whereDoesntHave('scheduleSlots', fn (Builder $q) => $q->whereNull('dispatched_at'));
    }

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'downloaded_at' => 'datetime',
            'ready_at' => 'datetime',
            'template_rendered_at' => 'datetime',
            'posted_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'posted_youtube_at' => 'datetime',
            'posted_tiktok_at' => 'datetime',
        ];
    }
}
