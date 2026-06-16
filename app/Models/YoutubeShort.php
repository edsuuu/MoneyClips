<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\YoutubeShortFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um Short do YouTube baixado de um canal e armazenado no MinIO.
 *
 * O ciclo de vida é: baixado (video_path + downloaded_at preenchidos) →
 * sorteado pelo comando youtube:dispatch-posts → postado (posted_at +
 * youtube_video_id preenchidos pelo ShortsPoster).
 *
 * @property int $id
 * @property string $youtube_id
 * @property string|null $channel_url
 * @property string|null $title
 * @property array<int, string>|null $hashtags
 * @property string|null $video_path
 * @property string|null $youtube_video_id
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $posted_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $posted_youtube_at
 * @property Carbon|null $posted_tiktok_at
 */
final class YoutubeShort extends Model
{
    /** @use HasFactory<YoutubeShortFactory> */
    use HasFactory;

    protected $fillable = [
        'youtube_id', 'channel_url', 'title', 'hashtags',
        'video_path', 'youtube_video_id', 'downloaded_at', 'posted_at',
        'dispatched_at', 'posted_youtube_at', 'posted_tiktok_at',
    ];

    /**
     * Shorts baixados (têm vídeo no storage), ainda não postados e ainda não
     * reservados pelo sorteio automático (dispatched_at).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeAvailableToPost(Builder $query): Builder
    {
        return $query
            ->whereNotNull('video_path')
            ->whereNull('posted_at')
            ->whereNull('dispatched_at');
    }

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'downloaded_at' => 'datetime',
            'posted_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'posted_youtube_at' => 'datetime',
            'posted_tiktok_at' => 'datetime',
        ];
    }
}
