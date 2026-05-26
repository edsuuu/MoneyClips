<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Conta de uma rede social conectada (token OAuth armazenado e criptografado).
 * Uma conta pertence a um usuário e é usada pelos publishers para postar de verdade.
 */
final class SocialAccount extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    public const array PLATFORMS = ['youtube', 'tiktok', 'instagram', 'facebook'];

    protected $fillable = [
        'uuid', 'user_id', 'platform', 'name', 'external_account_id',
        'access_token', 'refresh_token', 'token_expires_at', 'scopes',
        'meta', 'is_active',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ScheduledPost, $this>
     */
    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    public function tokenExpired(): bool
    {
        $expiresAt = $this->token_expires_at;
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }

        return Date::parse($expiresAt)->isPast();
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
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
