@props(['layout' => 'sidebar'])

<div
    x-data="{ open: false }"
    x-on:sidebar-toggle.window="open = !open"
    x-on:keydown.escape.window="open = false"
>
    <div x-show="open" x-cloak class="fixed inset-0 z-30 bg-slate-950/70 lg:hidden" x-on:click="open = false"></div>

    <aside
        @class([
            'fixed inset-y-0 left-0 z-10 flex w-64 -translate-x-full flex-col border-r border-slate-800 bg-slate-950/95 p-4 text-slate-100 backdrop-blur transition-transform duration-200 lg:translate-x-0',
            'lg:hidden' => $layout === 'navbar',
        ])
        :class="open ? 'translate-x-0' : ''"
    >
        <button type="button" class="mb-2 cursor-pointer self-end text-slate-400 hover:text-slate-100 lg:hidden" x-on:click="open = false">
            <x-ui.icon name="x-mark" class="size-5" />
        </button>

        <div class="px-2">
            <a href="{{ route('home') }}" wire:navigate class="flex size-10 items-center justify-center rounded-lg bg-slate-100 text-slate-950">
                <x-app-logo-icon class="size-6 fill-current" />
            </a>
        </div>

        <nav class="mt-8 px-2">
            <ul class="grid gap-1.5">
                @foreach ([
                    ['label' => 'Meus vídeos', 'icon' => 'layout-grid', 'route' => 'videos.index', 'pattern' => 'videos.*'],
                    ['label' => 'Agenda', 'icon' => 'calendar-days', 'route' => 'agenda.index', 'pattern' => 'agenda.*'],
                    ['label' => 'Estúdio', 'icon' => 'scissors', 'route' => 'reframe.index', 'pattern' => 'reframe.*'],
                    ['label' => 'Contas', 'icon' => 'user-circle', 'route' => 'accounts.index', 'pattern' => 'accounts.*'],
                    ['label' => 'Observabilidade', 'icon' => 'activity', 'route' => 'observability.index', 'pattern' => 'observability.*'],
                ] as $item)
                    <li>
                        <x-nav-item :icon="$item['icon']" size="lg" :href="route($item['route'])" :current="request()->routeIs($item['pattern'])">
                            {{ $item['label'] }}
                        </x-nav-item>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="flex-1"></div>

        @auth
            <x-user-menu placement="top" />
        @endauth
    </aside>
</div>
