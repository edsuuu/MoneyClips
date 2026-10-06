<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Enums\StockAssetEmotionEnum;
use App\Enums\StockAssetKindEnum;
use App\Enums\StockAssetLicenseEnum;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class StockAssetData
{
    public const string OPTIONS = '{--kind= : sfx, emoji, image, meme_sticker ou meme_clip}
        {--license= : cc0, public_domain, pixabay, pexels, apache2, own ou own_risk}
        {--tags= : separadas por vírgula}
        {--emotion=}
        {--source=manual}
        {--source-url=}
        {--author= : crédito exigido pela licença (CC BY)}
        {--real-person : mostra pessoa real}
        {--has-audio}
        {--risk-note=}';

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public StockAssetKindEnum $kind,
        public StockAssetLicenseEnum $license,
        public array $tags,
        public ?StockAssetEmotionEnum $emotion,
        public string $source,
        public ?string $sourceUrl,
        public ?string $author,
        public bool $showsRealPerson,
        public bool $hasAudio,
        public ?string $riskNote,
    ) {
        throw_if($kind->isMeme() && $license !== StockAssetLicenseEnum::OwnRisk, InvalidArgumentException::class, 'Meme exige --license=own_risk.');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromOptions(array $options): self
    {
        $kind = StockAssetKindEnum::tryFrom((string) $options['kind']);
        $license = StockAssetLicenseEnum::tryFrom((string) $options['license']);

        throw_if(is_null($kind) || is_null($license), InvalidArgumentException::class, '--kind e --license precisam de um valor válido.');

        $emotion = null;
        if (mb_trim((string) $options['emotion']) !== '') {
            $emotion = StockAssetEmotionEnum::tryFrom((string) $options['emotion'])
                ?? throw new InvalidArgumentException('--emotion inválida.');
        }

        $tags = array_values(array_unique(array_filter(array_map(
            fn (string $tag): string => (string) Str::of($tag)->ascii()->lower()->trim(),
            explode(',', (string) $options['tags']),
        ))));

        return new self(
            $kind,
            $license,
            $tags,
            $emotion,
            (string) $options['source'],
            mb_trim((string) $options['source-url']) === '' ? null : (string) $options['source-url'],
            mb_trim((string) $options['author']) === '' ? null : (string) $options['author'],
            (bool) $options['real-person'],
            (bool) $options['has-audio'],
            mb_trim((string) $options['risk-note']) === '' ? null : (string) $options['risk-note'],
        );
    }
}
