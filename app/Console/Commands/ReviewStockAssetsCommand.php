<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StockAssetEmotionEnum;
use App\Enums\StockAssetStatusEnum;
use App\Models\StockAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ReviewStockAssetsCommand extends Command
{
    /** @var string */
    protected $signature = 'assets:review';

    /** @var string */
    protected $description = 'Aprova ou recusa, um por um, os assets pending.';

    public function handle(): int
    {
        $pending = StockAsset::query()->where('status', StockAssetStatusEnum::Pending)->get();

        if ($pending->isEmpty()) {
            $this->info('Nenhum asset pending.');

            return self::SUCCESS;
        }

        foreach ($pending as $asset) {
            $this->line(sprintf(
                '%s | %s | %s | %s | tags: %s | emoção: %s | origem: %s | autor: %s',
                $asset->id,
                $asset->kind->value,
                $asset->license->value,
                Storage::disk('s3')->temporaryUrl($asset->storage_key, now()->addMinutes(30)),
                implode(',', $asset->tags),
                $asset->emotion->value ?? '-',
                $asset->source_url ?? $asset->source,
                $asset->author ?? '-',
            ));

            if ($asset->isRisky()) {
                $this->warn(sprintf(
                    'RISCO: pessoa real: %s | áudio: %s | %s',
                    $asset->shows_real_person ? 'sim' : 'não',
                    $asset->has_audio ? 'sim' : 'não',
                    $asset->risk_note ?? 'sem nota',
                ));
            }

            $question = $asset->isRisky() ? 'Aceita o risco e aprova?' : 'Aprovar?';

            match ($this->choice($question, ['aprovar', 'recusar', 'pular'], 'pular')) {
                'aprovar' => $asset->update([
                    'tags' => $this->askTags($asset),
                    'emotion' => $this->askEmotion($asset),
                    'status' => StockAssetStatusEnum::Approved,
                    'approved_at' => now(),
                ]),
                'recusar' => $asset->update(['status' => StockAssetStatusEnum::Disabled]),
                default => null,
            };
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function askTags(StockAsset $asset): array
    {
        $answer = (string) $this->ask('Tags finais (vírgula)', implode(',', $asset->tags));

        return array_values(array_unique(array_filter(array_map(
            fn (string $tag): string => (string) Str::of($tag)->ascii()->lower()->trim(),
            explode(',', $answer),
        ))));
    }

    private function askEmotion(StockAsset $asset): ?StockAssetEmotionEnum
    {
        if (! $asset->kind->isMeme()) {
            return $asset->emotion;
        }

        $values = array_map(fn (StockAssetEmotionEnum $emotion): string => $emotion->value, StockAssetEmotionEnum::cases());

        $choice = $this->choice('Emoção', $values, $asset->emotion?->value);

        return is_string($choice) ? StockAssetEmotionEnum::tryFrom($choice) : null;
    }
}
