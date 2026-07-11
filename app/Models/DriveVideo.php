<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um vídeo baixado de uma pasta pública do Google Drive e armazenado no MinIO
 * (bucket videos, sob o prefixo `drive/<pasta>/<arquivo>`).
 *
 * Preenchido pelo comando `drive:download-videos`.
 *
 * @property int $id
 * @property string $drive_file_id
 * @property string $drive_folder_id
 * @property string $drive_folder_url
 * @property string|null $folder_name
 * @property string $title
 * @property string $video_path
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property Carbon|null $downloaded_at
 */
final class DriveVideo extends Model
{
    protected $fillable = [
        'drive_file_id', 'drive_folder_id', 'drive_folder_url', 'folder_name',
        'title', 'video_path', 'mime_type', 'size_bytes', 'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'downloaded_at' => 'datetime',
        ];
    }
}
