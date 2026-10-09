<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;

final class AppLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public bool $navbar = true,
    ) {}

    public function render(): View
    {
        $routeName = Route::currentRouteName() ?? '';
        $tourSteps = Config::array('tour')[$routeName] ?? [];

        return view('layouts.app', [
            'tourName' => $routeName,
            'tourSteps' => $tourSteps,
            'hasTour' => $tourSteps !== [],
        ]);
    }
}
