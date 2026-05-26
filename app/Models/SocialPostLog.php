<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de log de uma publicação agendada (auditoria do que aconteceu ao postar).
 */
final class SocialPostLog extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const string LEVEL_INFO = 'info';

    public const string LEVEL_WARNING = 'warning';

    public const string LEVEL_ERROR = 'error';

    protected $fillable = ['scheduled_post_id', 'level', 'message', 'context', 'created_at'];

    /**
     * @return BelongsTo<ScheduledPost, $this>
     */
    public function scheduledPost(): BelongsTo
    {
        return $this->belongsTo(ScheduledPost::class);
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
