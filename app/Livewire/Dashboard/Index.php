<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\View\View;
use Livewire\Component;

final class Index extends Component
{
    public function render(): View
    {
        $videos = Video::query()->where('user_id', Auth::id());

        return view('livewire.dashboard.index', [
            'videoCount' => (clone $videos)->count(),
            'totalSizeLabel' => Number::fileSize((int) (clone $videos)->sum('file_size')),
        ]);
    }
}
