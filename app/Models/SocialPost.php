<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PostStatusEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $youtube_short_id
 * @property int $social_account_id
 * @property CarbonImmutable $scheduled_for
 * @property PostStatusEnum $status
 * @property string|null $privacy
 * @property string|null $external_id
 * @property string|null $url
 * @property string|null $error
 * @property int $attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $posted_at
 * @property-read YoutubeShort $youtubeShort
 * @property-read SocialAccount $socialAccount
 */
final class SocialPost extends Model
{
    protected $fillable = [
        'youtube_short_id', 'social_account_id', 'scheduled_for', 'status',
        'privacy', 'external_id', 'url', 'error', 'attempts', 'started_at', 'posted_at',
    ];

    /**
     * O dono do post é o dono da conta (sem user_id próprio). Admin vê tudo.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->whereRelation('socialAccount', 'user_id', $user->id);
    }

    /** @return BelongsTo<YoutubeShort, $this> */
    public function youtubeShort(): BelongsTo
    {
        return $this->belongsTo(YoutubeShort::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'status' => PostStatusEnum::class,
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }
}
