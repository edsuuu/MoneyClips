<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProcessingJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Um processamento de vídeo (reencode ou render de template no AutoCaption),
 * orquestrado pelo Laravel via fila `processing`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $youtube_short_id
 * @property string $type
 * @property string $status
 * @property array<string, mixed>|null $options
 * @property string|null $remote_id
 * @property string|null $output_path
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read YoutubeShort $youtubeShort
 */
final class ProcessingJob extends Model
{
    /** @use HasFactory<ProcessingJobFactory> */
    use HasFactory;

    public const string TYPE_REENCODE = 'reencode';

    public const string TYPE_TEMPLATE = 'template';

    /** Render pronto no AutoCaption; download do output em andamento. */
    public const string STATUS_FETCHING = 'fetching';

    public const array PENDING_STATUSES = ['queued', 'processing', self::STATUS_FETCHING];

    protected $fillable = [
        'uuid', 'youtube_short_id', 'type', 'status', 'options',
        'remote_id', 'output_path', 'error', 'started_at', 'finished_at',
    ];

    protected $attributes = ['status' => 'queued'];

    /** @return BelongsTo<YoutubeShort, $this> */
    public function youtubeShort(): BelongsTo
    {
        return $this->belongsTo(YoutubeShort::class);
    }

    protected static function booted(): void
    {
        self::creating(function (self $job): void {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Jobs ainda em andamento (bloqueiam novo processamento do mesmo vídeo).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', self::PENDING_STATUSES);
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
