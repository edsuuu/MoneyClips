<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Uma pasta pública do Google Drive rastreada pelo comando
 * `drive:download-videos`. O flag `downloaded` marca a pasta como baixada por
 * completo (nenhum vídeo falhou) — ao rodar de novo, pastas assim são puladas.
 *
 * @property int $id
 * @property string $drive_folder_id
 * @property string $url
 * @property string|null $name
 * @property bool $downloaded
 * @property int|null $videos_count
 * @property Carbon|null $downloaded_at
 */
final class DriveFolder extends Model
{
    protected $fillable = [
        'drive_folder_id', 'url', 'name', 'downloaded', 'videos_count', 'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'downloaded' => 'boolean',
            'videos_count' => 'integer',
            'downloaded_at' => 'datetime',
        ];
    }
}
