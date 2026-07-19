<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Último heartbeat de cada microserviço (1 linha por serviço, upsert).
 *
 * @property int $id
 * @property string $service
 * @property string|null $hostname
 * @property string|null $version
 * @property int $uptime_seconds
 * @property int|null $memory_mb
 * @property Carbon $last_seen_at
 */
final class ServiceHeartbeat extends Model
{
    public const int OFFLINE_AFTER_SECONDS = 90;

    protected $fillable = ['service', 'hostname', 'version', 'uptime_seconds', 'memory_mb', 'last_seen_at'];

    public function isOnline(): bool
    {
        return $this->last_seen_at->gt(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
    }

    protected function casts(): array
    {
        return [
            'uptime_seconds' => 'integer',
            'memory_mb' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
