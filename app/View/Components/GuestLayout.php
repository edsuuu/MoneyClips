<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Layout das telas públicas (landing, legal, auth) — sempre navbar.
 */
final class GuestLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        /** Landing ocupa a largura toda — o próprio conteúdo cuida do container. */
        public bool $bleed = false,
    ) {}

    public function render(): View
    {
        return view('layouts.guest', [
            // ?login=1 abre o modal já na carga (é pra onde /login redireciona).
            'openLogin' => request()->boolean('login'),
        ]);
    }
}
