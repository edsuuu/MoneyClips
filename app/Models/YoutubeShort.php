<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um Short do YouTube baixado de um canal e armazenado no MinIO.
 * Faz parte da funcionalidade isolada de download de Shorts.
 *
 * @property int $id
 * @property string $youtube_id
 * @property string|null $title
 * @property array<int, string>|null $hashtags
 * @property string|null $video_path
 * @property \Illuminate\Support\Carbon|null $downloaded_at
 */
final class YoutubeShort extends Model
{
    protected $fillable = [
        'youtube_id', 'title', 'hashtags',
        'video_path', 'downloaded_at',
    ];

    /**
     * @return HasMany<YoutubeShortJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(YoutubeShortJob::class);
    }

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'downloaded_at' => 'datetime',
        ];
    }
}
