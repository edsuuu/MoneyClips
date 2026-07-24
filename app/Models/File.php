<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um artefato de um vídeo no MinIO. O `type` diz o que é; `path` é a chave S3;
 * `meta` guarda o específico (renditions do hls, grade do storyboard, trecho do
 * corte). `upload_id` só existe no `original` enquanto o multipart está aberto.
 *
 * @property int $id
 * @property int $video_id
 * @property string $type
 * @property string $path
 * @property string|null $upload_id
 * @property int|null $size
 * @property string|null $mime_type
 * @property array<string, mixed>|null $meta
 * @property-read Video $video
 */
final class File extends Model
{
    public const string ORIGINAL = 'original';

    public const string AUDIO = 'audio';

    public const string HLS = 'hls';

    public const string POSTER = 'poster';

    public const string STORYBOARD = 'storyboard';

    public const string CLIP = 'clip';

    public const string TRANSCRIPT = 'transcript';

    protected $fillable = [
        'video_id', 'type', 'path', 'upload_id', 'size', 'mime_type', 'meta',
    ];

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'meta' => 'array',
        ];
    }
}
