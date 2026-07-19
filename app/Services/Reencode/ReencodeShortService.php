<?php

declare(strict_types=1);

namespace App\Services\Reencode;

use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class ReencodeShortService
{
    use TransfersStorageFiles;

    public function __construct(private ReencodeService $client) {}

    /**
     * @return bool true = reencodado (`processed_video_path` atualizado);
     *              false = microserviço pulou (qualidade já boa).
     *
     * @throws RuntimeException quando o vídeo não existe no MinIO.
     */
    public function reencode(YoutubeShort $short): bool
    {
        $sourceKey = (string) $short->video_path;
        throw_if($sourceKey === '' || ! Storage::disk('s3')->exists($sourceKey), RuntimeException::class, sprintf('Vídeo não encontrado no MinIO: "%s".', $sourceKey));

        $tmpSource = $this->pullToTemp($sourceKey, 'reencode-src-');
        $tmpOutput = (string) tempnam(sys_get_temp_dir(), 'reencode-out-');

        try {
            $reencoded = $this->client->reencode($tmpSource, $short->youtube_id, $tmpOutput);

            if ($reencoded) {
                $outputKey = $this->outputKeyFor($sourceKey);
                $this->pushToStorage($tmpOutput, $outputKey);

                $short->forceFill(['processed_video_path' => $outputKey])->save();
            }

            return $reencoded;
        } finally {
            @unlink($tmpSource);
            @unlink($tmpOutput);
        }
    }

    private function outputKeyFor(string $sourceKey): string
    {
        $replaced = preg_replace('/\.(\w+)$/', '_HQ.$1', $sourceKey);

        return is_string($replaced) && $replaced !== $sourceKey ? $replaced : $sourceKey.'_HQ.mp4';
    }
}
