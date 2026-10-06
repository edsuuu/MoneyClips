<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\StockAsset;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class StockAssetService
{
    /**
     * @throws Throwable
     */
    public function add(string $path, StockAssetData $data): StockAsset
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! is_file($path) || ! in_array($extension, $data->kind->allowedExtensions(), true)) {
            throw new InvalidArgumentException(sprintf('Arquivo inválido pra %s: %s', $data->kind->value, $path));
        }

        $probe = $this->probe($path, $extension);
        $id = (string) Str::uuid7();
        $key = sprintf('assets/%s/%s.%s', $data->kind->value, $id, $extension);

        throw_if(Storage::disk('s3')->putFileAs(dirname($key), new File($path), basename($key)) === false, RuntimeException::class, 'Falha ao subir o arquivo pro MinIO: '.$path);

        try {
            return StockAsset::query()->create([
                'id' => $id,
                'kind' => $data->kind,
                'tags' => $data->tags,
                'emotion' => $data->emotion?->value,
                'license' => $data->license,
                'source' => $data->source,
                'source_url' => $data->sourceUrl,
                'storage_key' => $key,
                'duration_ms' => $probe['duration_ms'],
                'width' => $probe['width'],
                'height' => $probe['height'],
                'author' => $data->author,
                'shows_real_person' => $data->showsRealPerson,
                'has_audio' => $data->hasAudio,
                'risk_note' => $data->riskNote,
            ]);
        } catch (Throwable $throwable) {
            Storage::disk('s3')->delete($key);

            Log::channel('daily')->error('[ERRO] falha ao gravar o asset do estoque', [
                'exception' => $throwable,
                'message' => $throwable->getMessage(),
                'storage_key' => $key,
            ]);

            throw $throwable;
        }
    }

    /**
     * @return array{duration_ms: int|null, width: int|null, height: int|null}
     */
    private function probe(string $path, string $extension): array
    {
        $empty = ['duration_ms' => null, 'width' => null, 'height' => null];

        if (in_array($extension, ['png', 'webp'], true)) {
            $size = @getimagesize($path);

            return $size === false ? $this->probeFailed($path, $empty) : ['duration_ms' => null, 'width' => $size[0], 'height' => $size[1]];
        }

        $result = Process::run(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_entries', 'format=duration:stream=width,height', $path]);
        $json = json_decode($result->output(), true);
        $duration = data_get($json, 'format.duration');

        if ($result->failed() || ! is_numeric($duration)) {
            return $this->probeFailed($path, $empty);
        }

        $width = data_get($json, 'streams.0.width');
        $height = data_get($json, 'streams.0.height');

        return [
            'duration_ms' => (int) round((float) $duration * 1000),
            'width' => is_int($width) ? $width : null,
            'height' => is_int($height) ? $height : null,
        ];
    }

    /**
     * @param  array{duration_ms: null, width: null, height: null}  $empty
     * @return array{duration_ms: null, width: null, height: null}
     */
    private function probeFailed(string $path, array $empty): array
    {
        Log::channel('daily')->warning('[AVISO] não foi possível ler as dimensões/duração do asset', ['path' => $path]);

        return $empty;
    }
}
