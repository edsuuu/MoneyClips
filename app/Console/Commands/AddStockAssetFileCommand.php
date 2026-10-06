<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Assets\StockAssetData;
use App\Services\Assets\StockAssetService;
use Illuminate\Console\Command;
use Throwable;

final class AddStockAssetFileCommand extends Command
{
    /** @var string */
    protected $signature = 'assets:add-file {path} '.StockAssetData::OPTIONS;

    /** @var string */
    protected $description = 'Sobe um arquivo pro MinIO e registra o asset como pending.';

    public function handle(StockAssetService $assets): int
    {
        try {
            $asset = $assets->add((string) $this->argument('path'), StockAssetData::fromOptions($this->options()));
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s (%s) pending', $asset->id, $asset->storage_key));

        return self::SUCCESS;
    }
}
