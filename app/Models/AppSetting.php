<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Flag global da aplicação (key/value booleano), editável pelo front.
 * Mesmo padrão do PlatformSetting: leitura memoizada por request.
 *
 * @property int $id
 * @property string $key
 * @property bool $enabled
 */
final class AppSetting extends Model
{
    /** Modo aleatório da agenda: slot vazio devido recebe vídeo sorteado. */
    public const string RANDOM_MODE = 'random_mode';

    protected $fillable = ['key', 'enabled'];

    /**
     * Memoizado por PROCESSO (once() não é limpo fora de teste): ok no
     * scheduler (schedule:run = processo novo por minuto) e no queue:listen
     * (processo novo por job). NÃO rode worker como queue:work daemon — o
     * toggle da /agenda ficaria invisível até reiniciar o worker.
     */
    public static function isEnabled(string $key): bool
    {
        return (bool) (once(static fn (): array => self::query()->pluck('enabled', 'key')->all())[$key] ?? false);
    }

    public static function set(string $key, bool $enabled): void
    {
        self::query()->updateOrCreate(['key' => $key], ['enabled' => $enabled]);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
