<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ledger genérico de postagens em redes sociais. Substitui o antigo
 * TiktokPost — a coluna `platform` discrimina o destino (tiktok, youtube,
 * instagram, ...). Cada poster grava uma linha aqui pra o Laravel
 * conseguir mostrar histórico e impedir duplicação por (platform, short).
 *
 * Os Posters (App\Services\AutoPost\Posters) é quem criam/atualizam
 * essas linhas. Para o TikTok, o microserviço uploader manda o status
 * final via webhook (TiktokPostCallbackController).
 *
 * @property int $id
 * @property string $platform
 * @property string $uuid
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
 */
final class SocialPost extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    public const string PLATFORM_TIKTOK = 'tiktok';

    public const string PLATFORM_YOUTUBE = 'youtube';

    /** Status que contam como "já postado/em andamento" para o sorteio. */
    public const array ACTIVE_STATUSES = ['queued', 'processing', 'completed', 'dry-run'];

    protected $fillable = [
        'platform', 'uuid', 'youtube_id', 'video_key', 'title', 'hashtags',
        'account_name', 'status', 'error', 'requested_at', 'started_at', 'posted_at',
    ];

    /**
     * Filtra por plataforma. Use `SocialPost::query()->platform('tiktok')`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    /**
     * Posts enfileirados, em andamento ou concluídos — bloqueiam novo sorteio
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
