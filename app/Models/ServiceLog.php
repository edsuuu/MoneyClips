<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * Linha de log recebida de um microserviço (push em lote). Sem updated_at —
 * logs são imutáveis. Prune diário: 14 dias (scheduler roda model:prune).
 *
 * @property int $id
 * @property string $service
 * @property string|null $hostname
 * @property string $level
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property Carbon $logged_at
 */
final class ServiceLog extends Model
{
    use Prunable;

    public const null UPDATED_AT = null;

    public const int RETENTION_DAYS = 14;

    protected $fillable = ['service', 'hostname', 'level', 'message', 'context', 'logged_at'];

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'logged_at' => 'datetime',
        ];
    }
}
