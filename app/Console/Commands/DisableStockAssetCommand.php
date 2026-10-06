<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StockAssetStatusEnum;
use App\Models\StockAsset;
use Illuminate\Console\Command;

final class DisableStockAssetCommand extends Command
{
    /** @var string */
    protected $signature = 'assets:disable {id}';

    /** @var string */
    protected $description = 'Desativa um asset (some do que a IA enxerga).';

    public function handle(): int
    {
        $asset = StockAsset::query()->find((string) $this->argument('id'));

        if (is_null($asset)) {
            $this->error('Asset não encontrado.');

            return self::FAILURE;
        }

        $asset->update(['status' => StockAssetStatusEnum::Disabled]);
        $this->info('Desativado: '.$asset->id);

        return self::SUCCESS;
    }
}
