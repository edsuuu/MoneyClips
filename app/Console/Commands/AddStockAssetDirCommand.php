<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Assets\StockAssetData;
use App\Services\Assets\StockAssetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class AddStockAssetDirCommand extends Command
{
    /** @var string */
    protected $signature = 'assets:add-dir {path} '.StockAssetData::OPTIONS;

    /** @var string */
    protected $description = 'Sobe os arquivos de uma pasta (sem recursão) pro MinIO como assets pending.';

    public function handle(StockAssetService $assets): int
    {
        $directory = (string) $this->argument('path');

        if (! is_dir($directory)) {
            $this->error('Pasta não encontrada: '.$directory);

            return self::FAILURE;
        }

        try {
            $data = StockAssetData::fromOptions($this->options());
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $added = 0;
        $failed = 0;

        foreach (File::files($directory) as $file) {
            try {
                $assets->add($file->getPathname(), $data);
                $added++;
            } catch (Throwable $exception) {
                $this->warn($exception->getMessage());
                $failed++;
            }
        }

        $this->info(sprintf('%d adicionado(s), %d ignorado(s).', $added, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
