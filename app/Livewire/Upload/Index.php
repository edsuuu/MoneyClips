<?php

declare(strict_types=1);

namespace App\Livewire\Upload;

use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class Index extends Component
{
    use WithFileUploads;

    public const int MAX_GIGABYTES = 3;

    public const int MAX_KILOBYTES = self::MAX_GIGABYTES * 1024 * 1024;

    public const array MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];

    public ?TemporaryUploadedFile $video = null;

    public bool $done = false;

    public function updatedVideo(): void
    {
        $this->validate();

        $file = $this->video;

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        $uuid = (string) Str::uuid();

        // ponytail: md5_file() lê em blocos (memória constante), mas gasta
        // CPU proporcional ao tamanho — ~5-8s num arquivo de 3GB, síncrono
        // na request. Se incomodar, mover o hash pra um job na fila.
        $hash = md5_file($file->getRealPath());

        Storage::disk('s3')->putFileAs(Video::DIRECTORY, $file, $uuid);

        Video::query()->create([
            'user_id' => Auth::id(),
            'uuid' => $uuid,
            'hash' => $hash,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        $this->reset('video');
        $this->done = true;
    }

    public function uploadAnother(): void
    {
        $this->reset(['video', 'done']);
        $this->resetValidation();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'video' => [
                'required',
                'file',
                'mimetypes:'.implode(',', self::MIME_TYPES),
                'max:'.self::MAX_KILOBYTES,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'video.required' => 'Escolha um vídeo para enviar.',
            'video.file' => 'O arquivo enviado é inválido.',
            'video.mimetypes' => 'Formato não suportado — envie MP4, MOV ou WEBM.',
            'video.max' => 'O vídeo passa do limite de 3GB.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return ['video' => 'vídeo'];
    }

    public function render(): View
    {
        return view('livewire.upload.index', [
            'maxLabel' => self::MAX_GIGABYTES.'GB',
            'acceptedLabel' => 'MP4, MOV, WEBM',
            'accept' => implode(',', self::MIME_TYPES),
        ]);
    }
}
