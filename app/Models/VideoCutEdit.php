<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use Database\Factories\VideoCutEditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Estado de uma edição (crop 9:16 por keyframes) de um corte. Coordenadas dos
 * keyframes são normalizadas (0–1) em relação ao tamanho natural da fonte — o
 * render ffmpeg multiplica por iw/ih reais. A fonte é o clipe do corte pai; o
 * resultado do render vira uma row em `files` (type `edit`).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $youtube_short_id
 * @property int|null $video_cut_id
 * @property array{width: int, height: int, duration: float}|null $source_meta
 * @property string $mode
 * @property VideoCutStatusEnum|null $render_status
 * @property string|null $render_error
 * @property TranscriptionStatusEnum|null $tracking_status
 * @property string|null $tracking_error
 * @property-read YoutubeShort|null $youtubeShort
 * @property-read VideoCut|null $videoCut
 */
final class VideoCutEdit extends Model
{
    /** @use HasFactory<VideoCutEditFactory> */
    use HasFactory;

    protected $table = 'video_cuts_edits';

    protected $fillable = [
        'uuid', 'youtube_short_id', 'video_cut_id', 'source_meta',
        'mode', 'keyframes', 'settings', 'render_status', 'render_error',
        'tracking_status', 'tracking_error',
    ];

    public function renderOutputPath(): string
    {
        $cut = $this->videoCut;

        throw_unless($cut instanceof VideoCut, RuntimeException::class, 'Edição sem corte pai não tem path de render.');

        return $cut->prefix().'/edits/'.$this->uuid.'/vertical.mp4';
    }

    /** @return BelongsTo<YoutubeShort, $this> */
    public function youtubeShort(): BelongsTo
    {
        return $this->belongsTo(YoutubeShort::class);
    }

    /** @return BelongsTo<VideoCut, $this> */
    public function videoCut(): BelongsTo
    {
        return $this->belongsTo(VideoCut::class);
    }

    protected static function booted(): void
    {
        self::creating(function (self $edit): void {
            $edit->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'source_meta' => 'array',
            'keyframes' => 'array',
            'settings' => 'array',
            'render_status' => VideoCutStatusEnum::class,
            'tracking_status' => TranscriptionStatusEnum::class,
        ];
    }
}
