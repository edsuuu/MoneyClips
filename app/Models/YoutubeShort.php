<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\YoutubeShortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Um Short do YouTube baixado de um canal e armazenado no MinIO.
 *
 * Ciclo de vida do estoque: baixado (video_path + downloaded_at) →
 * pronto para postar (ready_at) → postado (posted_youtube_at /
 * posted_tiktok_at + social_posts por plataforma).
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
 * @property Carbon|null $posted_youtube_at
 * @property Carbon|null $posted_tiktok_at
 */
final class YoutubeShort extends Model
{
    /** @use HasFactory<YoutubeShortFactory> */
    use HasFactory;

    private const int PRESIGNED_TTL_MINUTES = 30;

    protected $fillable = [
        'youtube_id', 'channel_url', 'title', 'hashtags',
        'video_path', 'processed_video_path', 'youtube_video_id',
        'downloaded_at', 'ready_at', 'template_rendered_at', 'posted_at',
        'posted_youtube_at', 'posted_tiktok_at',
    ];

    public function postableVideoPath(): string
    {
        return (string) ($this->processed_video_path ?? $this->video_path);
    }

    public function presignedUrl(): ?string
    {
        $path = $this->postableVideoPath();
        if ($path === '') {
            return null;
        }

        try {
            return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(self::PRESIGNED_TTL_MINUTES));
        } catch (Throwable) {
            return null;
        }
    }

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'downloaded_at' => 'datetime',
            'ready_at' => 'datetime',
            'template_rendered_at' => 'datetime',
            'posted_at' => 'datetime',
            'posted_youtube_at' => 'datetime',
            'posted_tiktok_at' => 'datetime',
        ];
    }
}
