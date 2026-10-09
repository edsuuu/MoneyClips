<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PostProviderEnum;
use App\Enums\SocialAccountModeEnum;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'uuid', 'user_id', 'platform', 'provider', 'name', 'external_account_id',
        'access_token', 'refresh_token', 'token_expires_at', 'scopes',
        'meta', 'is_active', 'auto_schedule', 'cookies', 'cookies_last_validated_at', 'session_status',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'cookies'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<SocialPost, $this> */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function mode(): SocialAccountModeEnum
    {
        return match (true) {
            ! $this->is_active => SocialAccountModeEnum::Off,
            $this->auto_schedule => SocialAccountModeEnum::Auto,
            default => SocialAccountModeEnum::Manual,
        };
    }

    public function applyMode(SocialAccountModeEnum $mode): void
    {
        $this->is_active = $mode !== SocialAccountModeEnum::Off;
        $this->auto_schedule = $mode === SocialAccountModeEnum::Auto;
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
            'auto_schedule' => 'boolean',
            'provider' => PostProviderEnum::class,
            'cookies' => 'encrypted:array',
            'cookies_last_validated_at' => 'datetime',
        ];
    }
}
