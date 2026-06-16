<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ledger de postagens no TikTok via microserviço tiktok-uploader.
 *
 * As linhas são criadas/atualizadas pela API do uploader (Node), que escreve
 * direto neste banco. O Laravel usa a tabela para excluir Shorts já postados
 * do sorteio (tiktok:dispatch-posts) e para exibir o histórico.
 *
 * @property int $id
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
final class TiktokPost extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    /** Status que contam como "já postado/em andamento" para o sorteio. */
    public const array ACTIVE_STATUSES = ['queued', 'processing', 'completed', 'dry-run'];

    protected $fillable = [
        'uuid', 'youtube_id', 'video_key', 'title', 'hashtags', 'account_name',
        'status', 'error', 'requested_at', 'started_at', 'posted_at',
    ];

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
