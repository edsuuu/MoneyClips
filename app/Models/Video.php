<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\HLS\VideoStatusEnum;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Vídeo longo enviado na tela /upload — matéria-prima para cortar em Shorts.
 *
 * O binário sobe direto do browser para o MinIO por multipart presigned (o
 * Laravel só assina as partes), fica em `uploads/{uuid}` e depois é empacotado
 * em HLS/ABR pelo microserviço `hls`, que escreve sob `hls/{uuid}/` — segunda
 * exceção à regra "só o Laravel toca o S3", junto com o download-shorts.
 *
 * Ciclo: awaiting_upload → uploaded → packaging → ready.
 *
 * @property int $id
 * @property int $user_id
 * @property string $uuid
 * @property string|null $hash
 * @property int $file_size
 * @property string $mime_type
 * @property VideoStatusEnum $status
 * @property string|null $upload_id
 * @property string|null $hls_remote_id
 * @property int $progress
 * @property int|null $duration_seconds
 * @property int|null $width
 * @property int|null $height
 * @property string|null $hls_path
 * @property string|null $poster_path
 * @property array<int, string>|null $renditions
 * @property string|null $error
 * @property Carbon|null $ready_at
 * @property-read User $user
 */
final class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    public const string DIRECTORY = 'uploads';

    public const string HLS_DIRECTORY = 'hls';

    public const string MASTER_PLAYLIST = 'master.m3u8';

    public const int MAX_GIGABYTES = 3;

    public const int MAX_BYTES = self::MAX_GIGABYTES * 1024 * 1024 * 1024;

    public const array MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];

    private const int PRESIGNED_TTL_MINUTES = 30;

    protected $fillable = [
        'user_id', 'uuid', 'hash', 'file_size', 'mime_type',
        'status', 'upload_id', 'hls_remote_id', 'progress',
        'duration_seconds', 'width', 'height',
        'hls_path', 'poster_path', 'renditions', 'error', 'ready_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function path(): string
    {
        return self::DIRECTORY.'/'.$this->uuid;
    }

    public function hlsPrefix(): string
    {
        return self::HLS_DIRECTORY.'/'.$this->uuid;
    }

    public function masterPlaylistPath(): string
    {
        return $this->hlsPrefix().'/'.self::MASTER_PLAYLIST;
    }

    public function isReady(): bool
    {
        return $this->status->isPlayable();
    }

    /**
     * URL direta do MP4 original — fallback enquanto o HLS não fica pronto.
     */
    public function presignedUrl(): ?string
    {
        try {
            return Storage::disk('s3')->temporaryUrl($this->path(), now()->addMinutes(self::PRESIGNED_TTL_MINUTES));
        } catch (Throwable) {
            return null;
        }
    }

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'status' => VideoStatusEnum::class,
            'progress' => 'integer',
            'duration_seconds' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'renditions' => 'array',
            'ready_at' => 'datetime',
        ];
    }
}
