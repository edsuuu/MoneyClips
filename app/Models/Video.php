<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vídeo enviado na tela /upload. O binário fica no MinIO em `uploads/{uuid}`
 * (disk `s3`) — só o Laravel toca o storage, conforme a regra do projeto.
 *
 * @property int $id
 * @property int $user_id
 * @property string $uuid
 * @property string $hash
 * @property int $file_size
 * @property string $mime_type
 * @property-read User $user
 */
final class Video extends Model
{
    public const string DIRECTORY = 'uploads';

    protected $fillable = ['user_id', 'uuid', 'hash', 'file_size', 'mime_type'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function path(): string
    {
        return self::DIRECTORY.'/'.$this->uuid;
    }

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }
}
