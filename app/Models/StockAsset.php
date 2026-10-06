<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockAssetEmotionEnum;
use App\Enums\StockAssetKindEnum;
use App\Enums\StockAssetLicenseEnum;
use App\Enums\StockAssetStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property StockAssetKindEnum $kind
 * @property list<string> $tags
 * @property StockAssetEmotionEnum|null $emotion
 * @property StockAssetLicenseEnum $license
 * @property string $source
 * @property string|null $source_url
 * @property string $storage_key
 * @property int|null $duration_ms
 * @property int|null $width
 * @property int|null $height
 * @property string|null $author
 * @property StockAssetStatusEnum $status
 * @property bool $shows_real_person
 * @property bool $has_audio
 * @property string|null $risk_note
 * @property Carbon|null $approved_at
 */
final class StockAsset extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'kind', 'tags', 'emotion', 'license', 'source', 'source_url', 'storage_key',
        'duration_ms', 'width', 'height', 'author', 'status', 'shows_real_person', 'has_audio', 'risk_note', 'approved_at',
    ];

    protected $attributes = ['status' => 'pending'];

    public function isRisky(): bool
    {
        return $this->shows_real_person || $this->has_audio;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => StockAssetKindEnum::class,
            'emotion' => StockAssetEmotionEnum::class,
            'license' => StockAssetLicenseEnum::class,
            'status' => StockAssetStatusEnum::class,
            'tags' => 'array',
            'duration_ms' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'shows_real_person' => 'boolean',
            'has_audio' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }
}
