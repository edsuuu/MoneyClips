<header x-data="{ menuOpen: false }" class="sticky top-0 z-40 border-b border-slate-800 bg-slate-950/95 px-4 py-3">
    <div class="flex items-center gap-x-4">
        <button
            type="button"
            x-on:click="menuOpen = true"
            class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-slate-300 transition hover:bg-slate-800/60 lg:hidden"
            aria-label="Abrir menu"
        >
            <x-ui.icon name="bars-2" class="size-5" />
        </button>

        <a
            href="{{ route('dashboard.index') }}"
            wire:navigate
            class="flex h-9 shrink-0 items-center gap-2.5"
        >
            <x-brand-mark class="size-8 shrink-0 drop-shadow-[0_0_9px_rgba(124,58,237,0.55)]" />
            <span class="whitespace-nowrap font-inter text-[15px] font-extrabold leading-none tracking-[0.14em] text-slate-50">UNK<span class="text-[#a855f7]">VOID</span></span>
        </a>

        <div
            x-show="menuOpen"
            x-cloak
            x-transition.opacity
            x-on:click="menuOpen = false"
            class="fixed inset-0 z-40 bg-black/50 lg:hidden"
        ></div>

        <nav
            x-on:keydown.escape.window="menuOpen = false"
            x-bind:class="menuOpen ? 'max-lg:translate-x-0!' : ''"
            class="max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-50 max-lg:w-64 max-lg:-translate-x-full max-lg:overflow-y-auto max-lg:border-r max-lg:border-slate-800 max-lg:bg-slate-950 max-lg:p-4 max-lg:transition-transform max-lg:duration-200 lg:flex-1"
        >
            <ul class="flex items-center gap-1 max-lg:flex-col max-lg:items-stretch lg:justify-center">
                @foreach ([
                    ['label' => 'Dashboard', 'icon' => 'layout-grid', 'route' => 'dashboard.index', 'pattern' => 'dashboard.*'],
                    ['label' => 'Meus vídeos', 'icon' => 'film', 'route' => 'videos.index', 'pattern' => 'videos.*'],
                    ['label' => 'Biblioteca', 'icon' => 'film', 'route' => 'uploads.index', 'pattern' => 'uploads.*'],
                ] as $item)
                    <li class="shrink-0" x-on:click="menuOpen = false">
                        <x-nav-item
                            :icon="$item['icon']"
                            :href="route($item['route'])"
                            :current="request()->routeIs($item['pattern'])"
                        >
                            <span class="whitespace-nowrap">{{ $item['label'] }}</span>
                        </x-nav-item>
                    </li>
                @endforeach
            </ul>
        </nav>

        @auth
            <div class="ml-auto flex items-center gap-1">
                <x-theme-toggle />
                <x-user-menu align="end" class="w-44" />
            </div>
        @endauth
    </div>
</header>
