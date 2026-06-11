<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Conversões seguras de valores `mixed` (config, JSON de API, payloads) para
 * tipos escalares, evitando casts diretos que o PHPStan (nível max) reprova.
 */
final class Cast
{
    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @return array<mixed>
     */
    public static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
