<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Fonte ÚNICA dos itens de navegação — consumida pela navbar desktop
 * (components/navbar-items.blade.php) e pelo drawer mobile
 * (components/sidebar.blade.php), pra rota/ícone/active nunca divergirem.
 */
final class NavbarItems extends Component
{
    private const array ITEMS = [
        ['label' => 'Meus vídeos', 'icon' => 'layout-grid', 'route' => 'videos.index', 'pattern' => 'videos.*'],
        ['label' => 'Agenda', 'icon' => 'calendar-days', 'route' => 'agenda.index', 'pattern' => 'agenda.*'],
        ['label' => 'Estúdio', 'icon' => 'scissors', 'route' => 'reframe.index', 'pattern' => 'reframe.*'],
        ['label' => 'Contas', 'icon' => 'user-circle', 'route' => 'accounts.index', 'pattern' => 'accounts.*'],
        ['label' => 'Observabilidade', 'icon' => 'activity', 'route' => 'observability.index', 'pattern' => 'observability.*'],
    ];

    /**
     * Itens com o estado `current` já resolvido.
     *
     * @return list<array{label: string, icon: string, route: string, current: bool}>
     */
    public static function items(): array
    {
        return array_map(static fn (array $item): array => [
            'label' => $item['label'],
            'icon' => $item['icon'],
            'route' => $item['route'],
            'current' => request()->routeIs($item['pattern']),
        ], self::ITEMS);
    }

    public function render(): View
    {
        return view('components.navbar-items', ['items' => self::items()]);
    }
}
