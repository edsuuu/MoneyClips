<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VideoStatusEnum;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Vídeo longo enviado na tela /upload — matéria-prima para cortar em Shorts.
 *
 * A entidade só guarda o objetivo do vídeo; todo binário/artefato vive em
 * `files` sob o prefixo `videos/{uuid}/` no MinIO (original, áudio, HLS, poster,
 * storyboard, cortes). Os paths são deriváveis do uuid; a tabela `files` diz o
 * que EXISTE e carrega o `meta` de cada artefato.
 *
 * Ciclo: awaiting_upload → uploaded → packaging → ready.
 *
 * @property int $id
 * @property int $user_id
 * @property string $uuid
 * @property string|null $hash
 * @property string|null $name
 * @property VideoStatusEnum $status
 * @property int $progress
 * @property int|null $duration_seconds
 * @property int|null $width
 * @property int|null $height
 * @property string|null $error
 * @property Carbon|null $ready_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property-read User $user
 * @property-read Collection<int, File> $files
 */
final class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    use SoftDeletes;

    public const string PREFIX = 'videos';

    public const int MAX_GIGABYTES = 3;

    public const int MAX_BYTES = self::MAX_GIGABYTES * 1024 * 1024 * 1024;

    public const array MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];

    private const int PRESIGNED_TTL_MINUTES = 30;

    protected $fillable = [
        'user_id', 'uuid', 'hash', 'name', 'status', 'progress',
        'duration_seconds', 'width', 'height', 'error', 'ready_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<File, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function file(string $type): ?File
    {
        return $this->files->firstWhere('type', $type);
    }

    /**
     * Cada operador só enxerga o que subiu: o vídeo alheio não resolve na rota e
     * vira 404. É o único ponto de dono — toda rota `{video:uuid}` passa por aqui.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->where('user_id', Auth::id())
            ->first();
    }

    public function prefix(): string
    {
        return self::PREFIX.'/'.$this->uuid;
    }

    public static function originalPathFor(string $uuid): string
    {
        return self::PREFIX.'/'.$uuid.'/'.$uuid.'.mp4';
    }

    public function originalPath(): string
    {
        return self::originalPathFor($this->uuid);
    }

    public function hlsPrefix(): string
    {
        return $this->prefix().'/hls';
    }

    public function masterPlaylistPath(): string
    {
        return $this->hlsPrefix().'/master.m3u8';
    }

    public function posterPath(): string
    {
        return $this->prefix().'/poster.jpg';
    }

    public function audioPath(): string
    {
        return $this->prefix().'/audio/audio.m4a';
    }

    public function storyboardPath(): string
    {
        return $this->prefix().'/storyboard.jpg';
    }

    public function isReady(): bool
    {
        return $this->status->isPlayable();
    }

    /** @return list<string> */
    public function renditions(): array
    {
        $renditions = $this->file(File::HLS)?->meta['renditions'] ?? [];

        if (! is_array($renditions)) {
            return [];
        }

        return array_values(array_filter($renditions, is_string(...)));
    }

    /**
     * URL direta do MP4 original — fallback enquanto o HLS não fica pronto.
     */
    public function presignedUrl(): ?string
    {
        try {
            return Storage::disk('s3')->temporaryUrl($this->originalPath(), now()->addMinutes(self::PRESIGNED_TTL_MINUTES));
        } catch (Throwable) {
            return null;
        }
    }

    protected function casts(): array
    {
        return [
            'status' => VideoStatusEnum::class,
            'progress' => 'integer',
            'duration_seconds' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'ready_at' => 'datetime',
        ];
    }
}
