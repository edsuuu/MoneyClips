<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\DateOnlyCast;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleSlotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um slot da agenda de auto-postagem: data + horário (fuso America/Sao_Paulo)
 * com um vídeo atribuído. O dispatcher reivindica o slot atomicamente via
 * dispatched_at e dispara 1 job por plataforma habilitada; o resultado por
 * plataforma vive nas social_posts do slot.
 *
 * @property int $id
 * @property CarbonImmutable $slot_date
 * @property string $slot_time "HH:MM:SS"
 * @property int|null $youtube_short_id
 * @property bool $is_active
 * @property Carbon|null $dispatched_at
 * @property-read YoutubeShort|null $youtubeShort
 * @property-read Collection<int, SocialPost> $socialPosts
 */
final class ScheduleSlot extends Model
{
    /** @use HasFactory<ScheduleSlotFactory> */
    use HasFactory;

    public const int MAX_PER_DAY = 5;

    protected $fillable = ['slot_date', 'slot_time', 'youtube_short_id', 'is_active', 'dispatched_at'];

    /** @return BelongsTo<YoutubeShort, $this> */
    public function youtubeShort(): BelongsTo
    {
        return $this->belongsTo(YoutubeShort::class);
    }

    /** @return HasMany<SocialPost, $this> */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function scheduledAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->slot_date->format('Y-m-d').' '.$this->slot_time);
    }

    public function timeLabel(): string
    {
        return mb_substr((string) $this->slot_time, 0, 5);
    }

    /**
     * Slots da semana que começa em $monday (segunda-feira).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeForWeek(Builder $query, CarbonImmutable $monday): Builder
    {
        return $query->whereBetween('slot_date', [
            $monday->toDateString(),
            $monday->addDays(6)->toDateString(),
        ]);
    }

    /**
     * Slots devidos AGORA: ativos, com vídeo, não despachados, com horário
     * entre now-grace e now. A comparação é feita em wall time SP.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeDue(Builder $query, CarbonImmutable $now, int $graceMinutes): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('youtube_short_id')
            ->whereNull('dispatched_at')
            ->tap(fn (Builder $q) => $this->whereInDueWindow($q, $now, $graceMinutes));
    }

    /**
     * Slots VAZIOS devidos agora — só o modo aleatório olha pra eles
     * (o dispatcher sorteia um vídeo pronto e roda o fluxo reencode+post).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeDueEmpty(Builder $query, CarbonImmutable $now, int $graceMinutes): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNull('youtube_short_id')
            ->whereNull('dispatched_at')
            ->tap(fn (Builder $q) => $this->whereInDueWindow($q, $now, $graceMinutes));
    }

    /**
     * Janela "devido agora" [now-grace, now] — pode cruzar a meia-noite,
     * então compara por (date, time).
     *
     * @param  Builder<self>  $query
     */
    private function whereInDueWindow(Builder $query, CarbonImmutable $now, int $graceMinutes): void
    {
        $from = $now->subMinutes($graceMinutes);

        $query->where(function (Builder $q) use ($from, $now): void {
            if ($from->isSameDay($now)) {
                $q->where('slot_date', $now->toDateString())
                    ->whereBetween('slot_time', [$from->format('H:i:00'), $now->format('H:i:59')]);

                return;
            }

            $q->where(fn (Builder $q2): Builder => $q2
                ->where('slot_date', $from->toDateString())
                ->where('slot_time', '>=', $from->format('H:i:00')))
                ->orWhere(fn (Builder $q2): Builder => $q2
                    ->where('slot_date', $now->toDateString())
                    ->where('slot_time', '<=', $now->format('H:i:59')));
        });
    }

    /**
     * Slots futuros ainda não despachados (para o modal "Agendar").
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopePending(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();

        return $query
            ->whereNull('dispatched_at')
            ->where(fn (Builder $q): Builder => $q
                ->where('slot_date', '>', $now->toDateString())
                ->orWhere(fn (Builder $q2): Builder => $q2
                    ->where('slot_date', $now->toDateString())
                    ->where('slot_time', '>', $now->format('H:i:s'))));
    }

    protected function casts(): array
    {
        return [
            'slot_date' => DateOnlyCast::class,
            'is_active' => 'boolean',
            'dispatched_at' => 'datetime',
        ];
    }
}
