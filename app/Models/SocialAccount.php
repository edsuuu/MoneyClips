<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PostProviderEnum;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SocialAccount extends Model
{
    use BelongsToUser;

    /** @use HasFactory<Factory> */
    use HasFactory;

    public const array PLATFORMS = ['youtube', 'tiktok'];

    public const string SESSION_VALID = 'valid';

    public const string SESSION_INVALID = 'invalid';

    public const string SESSION_UNKNOWN = 'unknown';

    protected $fillable = [
        'uuid', 'user_id', 'platform', 'name', 'external_account_id',
        'access_token', 'refresh_token', 'token_expires_at', 'scopes',
        'meta', 'is_active', 'cookies', 'cookies_last_validated_at', 'session_status',
        // Credenciais de login da plataforma (TikTok sem OAuth). ponytail: em texto
        // puro por ora — upgrade: castar 'login_password' como 'encrypted' e re-salvar.
        'login_email', 'login_password',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'cookies', 'login_password'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tokenExpired(): bool
    {
        // Cast 'datetime' garante CarbonImmutable|null aqui.
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (is_null($model->getAttribute('provider'))) {
                $model->provider = PostProviderEnum::defaultFor($model->platform);
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
            'provider' => PostProviderEnum::class,
            'cookies' => 'encrypted:array',
            'cookies_last_validated_at' => 'datetime',
        ];
    }
}
