<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Job de postagem de um Short: guarda o horário agendado e o estado da postagem.
 * Parte da funcionalidade isolada de download de Shorts.
 *
 * @property int $id
 * @property int $youtube_short_id
 * @property Carbon $scheduled_at
 * @property Carbon|null $posted_at
 * @property string $status
 * @property bool $discord_notified
 * @property-read YoutubeShort $short
 */
final class YoutubeShortJob extends Model
{
    use HasFactory;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'youtube_short_id', 'scheduled_at', 'posted_at',
        'status', 'discord_notified',
    ];

    /**
     * @return BelongsTo<YoutubeShort, $this>
     */
    public function short(): BelongsTo
    {
        return $this->belongsTo(YoutubeShort::class, 'youtube_short_id');
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'posted_at' => 'datetime',
            'discord_notified' => 'boolean',
        ];
    }
}
