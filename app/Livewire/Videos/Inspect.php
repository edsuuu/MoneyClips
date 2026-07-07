<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Models\YoutubeShort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Inspeciona qualquer Short do estoque via ffprobe: resolução, bitrate, codec,
 * tamanho — a mesma leitura do script local video-inspect.js, porém a partir do
 * MinIO. Tenta uma URL assinada (sem download); se o storage não suportar,
 * baixa para um arquivo temporário e o apaga ao final.
 *
 * Ferramenta de dev — a rota fica atrás de auth.
 */
final class Inspect extends Component
{
    private const int MAX_RESULTS = 25;

    private const int PRESIGNED_TTL_MINUTES = 5;

    public string $search = '';

    public ?int $selectedShortId = null;

    /** @var array<string, mixed>|null */
    public ?array $metadata = null;

    public bool $loading = false;

    public ?string $error = null;

    public function probeVideo(int $shortId): void
    {
        $this->loading = true;
        $this->error = null;
        $this->metadata = null;
        $this->selectedShortId = $shortId;

        try {
            $short = YoutubeShort::query()->find($shortId);

            if (! $short instanceof YoutubeShort || $short->video_path === null || $short->video_path === '') {
                $this->error = 'Short não encontrado ou sem arquivo de vídeo.';

                return;
            }

            [$source, $tempPath] = $this->resolveSource($short->video_path);

            try {
                $this->metadata = $this->runFfprobe($short, $source);
            } finally {
                if ($tempPath !== null && File::exists($tempPath)) {
                    File::delete($tempPath);
                }
            }
        } catch (Throwable $throwable) {
            report($throwable);
            $this->error = 'Falha ao inspecionar o vídeo: '.$throwable->getMessage();
            $this->metadata = null;
        } finally {
            $this->loading = false;
        }
    }

    public function render(): View
    {
        $shorts = YoutubeShort::query()
            ->whereNotNull('video_path')
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.mb_trim($this->search).'%';
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('youtube_id', 'like', $term)
                        ->orWhere('title', 'like', $term)
                        ->orWhere('video_path', 'like', $term);
                });
            })
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        return view('livewire.videos.inspect', ['shorts' => $shorts]);
    }

    /**
     * Devolve [source, tempPath]. `source` é a URL assinada (tempPath = null) ou
     * o caminho do arquivo temporário baixado (tempPath = mesmo caminho, para
     * apagar depois).
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolveSource(string $videoPath): array
    {
        try {
            $url = Storage::disk('s3')->temporaryUrl(
                $videoPath,
                Date::now()->addMinutes(self::PRESIGNED_TTL_MINUTES),
            );

            return [$url, null];
        } catch (Throwable) {
            // Storage sem suporte a URL temporária — cai para download local.
        }

        $contents = Storage::disk('s3')->get($videoPath);

        throw_if($contents === null, RuntimeException::class, 'Arquivo não encontrado no storage.');

        $tempPath = tempnam(sys_get_temp_dir(), 'short_');

        throw_if($tempPath === false, RuntimeException::class, 'Não foi possível criar o arquivo temporário.');

        File::put($tempPath, $contents);

        return [$tempPath, $tempPath];
    }

    /**
     * @return array<string, mixed>
     */
    private function runFfprobe(YoutubeShort $short, string $source): array
    {
        $result = Process::timeout(60)->run([
            'ffprobe', '-v', 'quiet', '-print_format', 'json',
            '-show_streams', '-show_format', $source,
        ]);

        if (! $result->successful()) {
            $stderr = mb_trim($result->errorOutput());
            throw new RuntimeException('ffprobe falhou: '.($stderr === '' ? 'erro desconhecido' : $stderr));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        $data = is_array($decoded) ? $decoded : [];

        $streams = isset($data['streams']) && is_array($data['streams']) ? $data['streams'] : [];
        $format = isset($data['format']) && is_array($data['format']) ? $data['format'] : [];

        $video = $this->firstStream($streams, 'video');
        $audio = $this->firstStream($streams, 'audio');

        $videoBitrate = $this->asInt($video['bit_rate'] ?? null)
            ?? $this->asInt($format['bit_rate'] ?? null)
            ?? 0;

        return [
            'youtube_id' => $short->youtube_id,
            'title' => $short->title,
            'video_path' => $short->video_path,
            'width' => $this->asInt($video['width'] ?? null),
            'height' => $this->asInt($video['height'] ?? null),
            'duration' => $this->asFloat($format['duration'] ?? null) ?? 0.0,
            'fps' => $this->evalFps($this->asString($video['r_frame_rate'] ?? null)),
            'codec' => $this->asString($video['codec_name'] ?? null) ?? 'N/A',
            'profile' => $this->asString($video['profile'] ?? null) ?? 'N/A',
            'video_bitrate' => $videoBitrate,
            'audio_bitrate' => $this->asInt($audio['bit_rate'] ?? null) ?? 0,
            'audio_codec' => $this->asString($audio['codec_name'] ?? null) ?? 'N/A',
            'file_size' => $this->asInt($format['size'] ?? null) ?? 0,
            'format_name' => $this->asString($format['format_long_name'] ?? null)
                ?? $this->asString($format['format_name'] ?? null)
                ?? 'N/A',
        ];
    }

    /**
     * @param  array<int|string, mixed>  $streams
     * @return array<array-key, mixed>
     */
    private function firstStream(array $streams, string $type): array
    {
        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === $type) {
                return $stream;
            }
        }

        return [];
    }

    private function asInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function asFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function evalFps(?string $raw): ?float
    {
        if ($raw === null || $raw === '0/0') {
            return null;
        }

        $parts = explode('/', $raw);
        $num = (float) $parts[0];
        $den = (float) ($parts[1] ?? '1');

        return $den > 0.0 ? $num / $den : null;
    }
}
