<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class Index extends Component
{
    public function render(): View
    {
        return view('livewire.dashboard.index', [
            'videoCount' => Video::query()->where('user_id', Auth::id())->count(),
        ]);
    }
}
