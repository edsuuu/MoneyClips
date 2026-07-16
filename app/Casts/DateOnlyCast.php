<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast de data pura ("Y-m-d") — ao contrário do immutable_date nativo, GRAVA
 * sem hora. Necessário porque o cast nativo armazena "Y-m-d H:i:s": o MySQL
 * trunca em colunas DATE, mas o sqlite dos testes guarda o texto inteiro e
 * quebra toda comparação `where('slot_date', 'Y-m-d')`.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
final class DateOnlyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse(mb_substr((string) $value, 0, 10));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return mb_substr((string) $value, 0, 10);
    }
}
