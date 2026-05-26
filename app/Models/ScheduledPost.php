<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Cast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Uma publicação agendada de um corte (ou do vídeo) em uma plataforma.
 * É a entidade que alimenta o dashboard e que o worker publica de verdade.
 */
final class ScheduledPost extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    public const string STATUS_PENDING = 'pending';       // criado, sem conta/agenda confirmada

    public const string STATUS_SCHEDULED = 'scheduled';   // pronto, aguardando o horário

    public const string STATUS_PUBLISHING = 'publishing';  // worker pegou e está enviando

    public const string STATUS_POSTED = 'posted';         // publicado com sucesso

    public const string STATUS_FAILED = 'failed';         // falhou (ver error_message/logs)

    public const string STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'video_id', 'cut_id', 'social_account_id', 'platform',
        'sequence', 'title', 'description', 'hashtags', 'scheduled_for',
        'status', 'external_post_id', 'external_url', 'error_message',
        'attempts', 'posted_at', 'payload', 'created_by',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * @return BelongsTo<Cut, $this>
     */
    public function cut(): BelongsTo
    {
        return $this->belongsTo(Cut::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /**
     * @return HasMany<SocialPostLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(SocialPostLog::class)->latest('created_at');
    }

    /**
     * Registra uma linha de log e devolve-a.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function log(string $level, string $message, ?array $context = null): SocialPostLog
    {
        return $this->logs()->create([
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    /** @return list<string> */
    public function hashtagList(): array
    {
        $tags = Cast::arr($this->hashtags);

        return array_values(array_filter($tags, static fn ($t): bool => is_string($t) && $t !== ''));
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Posts cujo horário já chegou e que estão prontos para publicar.
     *
     * @param  Builder<ScheduledPost>  $query
     * @return Builder<ScheduledPost>
     */
    protected function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now());
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'attempts' => 'integer',
            'hashtags' => 'array',
            'payload' => 'array',
            'scheduled_for' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }
}
