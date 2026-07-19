<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReframeEditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Estado de uma edição de reframe/crop do /estudio-de-cortes. Coordenadas
 * dos keyframes são normalizadas (0–1) em relação ao tamanho natural da
 * fonte — o render ffmpeg futuro multiplica por iw/ih reais.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $youtube_short_id
 * @property string $source_path
 * @property array{width: int, height: int, duration: float}|null $source_meta
 * @property string $mode
 * @property list<array{t: float, regions: list<array{x: float, y: float, w: float, h: float}>}> $keyframes
 * @property array{version: int, background: string}|null $settings
 * @property-read YoutubeShort|null $youtubeShort
 */
final class ReframeEdit extends Model
{
    /** @use HasFactory<ReframeEditFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid', 'youtube_short_id', 'source_path', 'source_meta',
        'mode', 'keyframes', 'settings',
    ];

    /** @return BelongsTo<YoutubeShort, $this> */
    public function youtubeShort(): BelongsTo
    {
        return $this->belongsTo(YoutubeShort::class);
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
        ];
    }
}
