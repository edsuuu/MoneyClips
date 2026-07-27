<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Enums\VideoStatusEnum;
use App\Jobs\StartYoutubeDownloadJob;
use App\Models\Video;
use App\Services\Upload\Data\YoutubeVideoMetadataData;
use App\Services\Upload\DownloadYoutubeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;

/**
 * Casca da tela de envio: este componente nunca recebe arquivo. O upload vive
 * em `resources/js/Upload/` (TypeScript) e manda os bytes do browser direto pro
 * MinIO por multipart presigned — o Laravel só assina as partes e confere o
 * resultado, o que contorna `upload_max_filesize`/`post_max_size` e dá retomada
 * em arquivos de GBs. Aqui só saem os limites e o destino que o TS precisa.
 *
 * A importação por URL do YouTube é a exceção: essa roda no servidor mesmo —
 * valida a URL, busca metadata no microserviço download-youtube e cria o Video
 * em `downloading`; o binário chega por webhook sem passar pelo browser.
 */
final class Create extends Component
{
    private const int TARGET_HEIGHT = 1080;

    public string $youtubeUrl = '';

    /** @var array{title: string, durationLabel: string, resolutionLabel: string, thumbnailUrl: ?string}|null */
    public ?array $youtubePreview = null;

    public function fetchYoutubeMetadata(DownloadYoutubeService $service): void
    {
        $this->youtubePreview = null;
        $this->validate();

        try {
            $metadata = $service->fetchMetadata($this->youtubeUrl);
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            $this->addError('youtubeUrl', 'Não foi possível consultar o YouTube agora. Tente de novo.');

            return;
        }

        if (! $metadata instanceof YoutubeVideoMetadataData) {
            $this->addError('youtubeUrl', 'Vídeo não encontrado ou indisponível.');

            return;
        }

        $this->youtubePreview = [
            'title' => $metadata->title !== '' ? $metadata->title : $metadata->youtubeId,
            'durationLabel' => $this->formatDuration($metadata->durationSeconds),
            'resolutionLabel' => min($metadata->height ?? self::TARGET_HEIGHT, self::TARGET_HEIGHT).'p',
            'thumbnailUrl' => $metadata->thumbnailUrl,
        ];
    }

    public function confirmYoutubeImport(): void
    {
        if ($this->youtubePreview === null) {
            return;
        }

        $video = Video::query()->create([
            'user_id' => Auth::id(),
            'uuid' => (string) Str::uuid(),
            'status' => VideoStatusEnum::Downloading,
            'name' => $this->youtubePreview['title'],
        ]);

        dispatch(new StartYoutubeDownloadJob($video->id, $this->youtubeUrl));

        $this->redirectRoute('uploads.index', navigate: true);
    }

    public function cancelYoutubeImport(): void
    {
        $this->youtubeUrl = '';
        $this->youtubePreview = null;
        $this->resetErrorBag('youtubeUrl');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'youtubeUrl' => [
                'required',
                'url',
                'regex:#^https?://(www\.|m\.)?(youtube\.com/(watch\?.*v=[\w-]+|shorts/[\w-]+|embed/[\w-]+|live/[\w-]+)|youtu\.be/[\w-]+)#',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'youtubeUrl.required' => 'Informe a URL do vídeo.',
            'youtubeUrl.url' => 'Informe uma URL válida.',
            'youtubeUrl.regex' => 'Informe a URL de um vídeo do YouTube.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return ['youtubeUrl' => 'URL do vídeo'];
    }

    private function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh%02dmin', $hours, $minutes);
        }

        return sprintf('%dmin%02ds', $minutes, $seconds % 60);
    }

    public function render(): View
    {
        return view('livewire.uploads.create', [
            'maxLabel' => Video::MAX_GIGABYTES.'GB',
            'maxBytes' => Video::MAX_BYTES,
            'acceptedLabel' => 'MP4, MOV, WEBM',
            'accept' => implode(',', Video::MIME_TYPES),
            'libraryUrl' => route('uploads.index'),
            'videoUrlBase' => route('uploads.index'),
        ]);
    }
}
