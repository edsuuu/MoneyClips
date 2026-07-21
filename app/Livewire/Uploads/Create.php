<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Models\Video;
use Illuminate\View\View;
use Livewire\Component;

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
