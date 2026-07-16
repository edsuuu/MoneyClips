<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlatformSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Toggle global por plataforma de postagem (tela /agenda). O cron lê daqui —
 * substitui as antigas colunas users.auto_post_{platform}_enabled.
 *
 * @property int $id
 * @property string $platform
 * @property string $display_name
 * @property bool $enabled
 */
final class PlatformSetting extends Model
{
    /** @use HasFactory<PlatformSettingFactory> */
    use HasFactory;

    protected $fillable = ['platform', 'display_name', 'enabled'];

    /** Memoizado por request — o dispatcher consulta várias plataformas por tick. */
    public static function isEnabled(string $platform): bool
    {
        return (bool) (self::map()[$platform]->enabled ?? false);
    }

    /** Nome de exibição da plataforma (fallback: o próprio identificador). */
    public static function displayName(string $platform): string
    {
        return self::map()[$platform]->display_name ?? ucfirst($platform);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * Todas as linhas indexadas por plataforma, numa única query memoizada.
     *
     * @return array<int|string, self>
     */
    private static function map(): array
    {
        return once(static fn (): array => self::query()->get()->keyBy('platform')->all());
    }
}
