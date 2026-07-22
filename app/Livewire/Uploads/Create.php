<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Models\Video;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Casca da tela de envio: este componente nunca recebe arquivo. O upload vive
 * em `resources/js/Upload/` (TypeScript) e manda os bytes do browser direto pro
 * MinIO por multipart presigned — o Laravel só assina as partes e confere o
 * resultado, o que contorna `upload_max_filesize`/`post_max_size` e dá retomada
 * em arquivos de GBs. Aqui só saem os limites e o destino que o TS precisa.
 */
final class Create extends Component
{
    public function render(): View
    {
        return view('livewire.uploads.create', [
            'maxLabel' => Video::MAX_GIGABYTES.'GB',
            'maxBytes' => Video::MAX_BYTES,
            'acceptedLabel' => 'MP4, MOV, WEBM',
            'accept' => implode(',', Video::MIME_TYPES),
            'libraryUrl' => route('uploads.index'),
            'videoUrlBase' => url('/meus-uploads'),
        ]);
    }
}
