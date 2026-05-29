<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

final class Video extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    protected $fillable = [
        'uuid', 'url', 'source_provider', 'external_video_id', 'title',
        'duration_seconds', 'status_id', 'current_stage', 'progress',
        'error_message', 'created_by', 'finished_at', 'is_auto',
        'auto_mode', 'auto_clip_count', 'face_tracking',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Status, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * @return HasMany<ProcessingJob, $this>
     */
    public function processingJobs(): HasMany
    {
        return $this->hasMany(ProcessingJob::class);
    }

    /**
     * @return HasMany<VideoPayload, $this>
     */
    public function payloads(): HasMany
    {
        return $this->hasMany(VideoPayload::class);
    }

    /**
     * @return HasOne<Transcript, $this>
     */
    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class);
    }

    /**
     * @return HasMany<Cut, $this>
     */
    public function cuts(): HasMany
    {
        return $this->hasMany(Cut::class)->orderBy('index');
    }

    /**
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * @return MorphMany<StatusLog, $this>
     */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'statusable');
    }

    public function fileOfType(string $type): ?File
    {
        $file = $this->files()->where('type', $type)->latest()->first();

        return $file instanceof File ? $file : null;
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'float',
            'progress' => 'integer',
            'finished_at' => 'datetime',
            'is_auto' => 'boolean',
            'auto_clip_count' => 'integer',
            'face_tracking' => 'boolean',
        ];
    }
}
