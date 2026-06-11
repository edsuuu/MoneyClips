<?php

declare(strict_types=1);

namespace App\Models;

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
 */
final class YoutubeShort extends Model
{
    use HasFactory;

    protected $fillable = [
        'youtube_id', 'channel_url', 'title', 'hashtags',
        'video_path', 'youtube_video_id', 'downloaded_at', 'posted_at',
    ];

    /**
     * Shorts baixados (têm vídeo no storage) e ainda não postados.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailableToPost(Builder $query): Builder
    {
        return $query->whereNotNull('video_path')->whereNull('posted_at');
    }

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'downloaded_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }
}
