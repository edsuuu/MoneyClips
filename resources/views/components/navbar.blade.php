<header class="sticky top-0 z-40 border-b border-slate-800 bg-slate-950/95 px-4 py-3 backdrop-blur">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <a
            href="{{ route('dashboard.index') }}"
            wire:navigate
            class="order-1 flex h-9 shrink-0 items-center gap-2.5"
        >
            <x-brand-mark class="size-8 shrink-0 drop-shadow-[0_0_9px_rgba(124,58,237,0.55)]" />
            <span class="whitespace-nowrap font-inter text-[15px] font-extrabold leading-none tracking-[0.14em] text-slate-50">UNK<span class="text-[#a855f7]">VOID</span></span>
        </a>

        @auth
            <x-user-menu align="end" class="order-2 ml-auto w-44 lg:order-3" />
        @endauth

        <nav class="order-3 w-full lg:order-2 lg:w-auto">
            <ul class="flex items-center gap-1 overflow-x-auto">
                @foreach ([
                    ['label' => 'Dashboard', 'icon' => 'layout-grid', 'route' => 'dashboard.index', 'pattern' => 'dashboard.*'],
                    ['label' => 'Meus vídeos', 'icon' => 'film', 'route' => 'videos.index', 'pattern' => 'videos.*'],
                    ['label' => 'Enviar vídeo', 'icon' => 'arrow-up-tray', 'route' => 'upload.index', 'pattern' => 'upload.*'],
                    ['label' => 'Biblioteca', 'icon' => 'film', 'route' => 'uploads.index', 'pattern' => 'uploads.*'],
                ] as $item)
                    <li class="shrink-0">
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
    </div>
</header>
