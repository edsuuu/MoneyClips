<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Models\Video;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Casca da tela de envio: o upload em si é multipart direto do browser para o
 * MinIO (resources/js/multipart-uploader.js), então o componente não recebe
 * arquivo — só oferece os limites e o destino para o JS.
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
        ]);
    }
}
