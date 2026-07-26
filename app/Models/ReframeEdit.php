<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VideoCutStatusEnum;
use Database\Factories\ReframeEditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Estado de uma edição de reframe/crop do /editor-de-video. Coordenadas
 * dos keyframes são normalizadas (0–1) em relação ao tamanho natural da
 * fonte — o render ffmpeg futuro multiplica por iw/ih reais.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $youtube_short_id
 * @property int|null $video_cut_id
 * @property string $source_path
 * @property array{width: int, height: int, duration: float}|null $source_meta
 * @property string $mode
 * @property list<array{t: float, regions: list<array{x: float, y: float, w: float, h: float}>}> $keyframes
 * @property array{version: int, background: string, captions?: bool, captionColor?: string, captionCase?: string}|null $settings
 * @property VideoCutStatusEnum|null $render_status
 * @property string|null $rendered_path
 * @property string|null $render_error
 * @property-read YoutubeShort|null $youtubeShort
 * @property-read VideoCut|null $videoCut
 */
final class ReframeEdit extends Model
{
    /** @use HasFactory<ReframeEditFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid', 'youtube_short_id', 'video_cut_id', 'source_path', 'source_meta',
        'mode', 'keyframes', 'settings', 'render_status', 'rendered_path', 'render_error',
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
        ];
    }
}
