<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Um corte (trecho start–end) de um vídeo longo. O arquivo gerado e os
 * derivados (áudio, transcrição, futuras edições) viram rows em `files`
 * apontando pra este corte via `video_cut_id`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $video_id
 * @property int $start_seconds
 * @property int $end_seconds
 * @property bool $is_ai_generated
 * @property VideoCutStatusEnum $status
 * @property TranscriptionStatusEnum|null $transcription_status
 * @property Carbon|null $edited_at
 * @property string|null $error
 * @property-read Video $video
 * @property-read Collection<int, File> $files
 */
final class VideoCut extends Model
{
    use SoftDeletes;

    public const int MAX_DURATION_SECONDS = 180;

    private const int PRESIGNED_TTL_MINUTES = 30;

    protected $fillable = [
        'uuid', 'video_id', 'start_seconds', 'end_seconds',
        'is_ai_generated', 'status', 'transcription_status', 'edited_at', 'error',
    ];

    protected $attributes = ['status' => 'draft'];

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /** @return HasMany<File, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function prefix(): string
    {
        return $this->video->prefix().'/cuts/'.$this->uuid;
    }

    public function clipPath(): string
    {
        return $this->prefix().'/original/clip.mp4';
    }

    public function clipAudioPath(): string
    {
        return $this->prefix().'/original/audio.wav';
    }

    public function transcriptPath(): string
    {
        return $this->prefix().'/original/transcript.json';
    }

    public function presignedUrl(): ?string
    {
        try {
            return Storage::disk('s3')->temporaryUrl($this->clipPath(), now()->addMinutes(self::PRESIGNED_TTL_MINUTES));
        } catch (Throwable) {
            return null;
        }
    }

    protected static function booted(): void
    {
        self::creating(function (self $cut): void {
            $cut->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'start_seconds' => 'integer',
            'end_seconds' => 'integer',
            'is_ai_generated' => 'boolean',
            'status' => VideoCutStatusEnum::class,
            'transcription_status' => TranscriptionStatusEnum::class,
            'edited_at' => 'datetime',
        ];
    }
}
