@props(['layout' => 'sidebar'])

<div
    x-data="{
        open: false,
        expanded: false,
        openTimer: null,
        closeTimer: null,
        clearTimers() { clearTimeout(this.openTimer); clearTimeout(this.closeTimer); this.openTimer = null; this.closeTimer = null },
        expand() {
            if (window.sidebarExpandLocked) return;
            clearTimeout(this.closeTimer); this.closeTimer = null;
            if (this.expanded || this.openTimer) return;
            this.openTimer = setTimeout(() => { this.expanded = true; this.openTimer = null }, 150);
        },
        scheduleCollapse() { this.clearTimers(); this.closeTimer = setTimeout(() => { this.expanded = false }, 300) },
        // O lock vive no window porque o wire:navigate recria este x-data com o
        // cursor ainda sobre a sidebar — sem ele o mousemove reabriria na hora.
        collapseNow() { this.clearTimers(); this.expanded = false; window.sidebarExpandLocked = true },
        unlock() { window.sidebarExpandLocked = false },
    }"
    x-on:sidebar-toggle.window="open = !open"
    x-on:keydown.escape.window="open = false"
>
    <div x-show="open" x-cloak class="fixed inset-0 z-30 bg-slate-950/70 lg:hidden" x-on:click="open = false"></div>

    <aside
        @class([
            'group fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-slate-800 bg-slate-950/95 p-2 text-slate-100 backdrop-blur transition-[width,transform] duration-200 lg:translate-x-0',
            'lg:hidden' => $layout === 'navbar',
        ])
        :class="[open ? 'translate-x-0' : '', expanded ? 'lg:w-64' : 'lg:w-16']"
        x-on:mouseleave="unlock(); scheduleCollapse(); $dispatch('dropdown-close')"
    >
        <button type="button" class="mb-1 cursor-pointer self-end px-2 text-slate-400 hover:text-slate-100 lg:hidden" x-on:click="open = false">
            <x-ui.icon name="x-mark" class="size-5" />
        </button>

        <a
            href="{{ route('dashboard.index') }}"
            wire:navigate
            x-on:mousemove="expand()"
            x-on:click="collapseNow()"
            class="mb-6 flex h-9 shrink-0 items-center gap-2.5 px-1.5"
        >
            <x-brand-mark class="size-8 shrink-0 drop-shadow-[0_0_9px_rgba(124,58,237,0.55)]" />
            <span
                class="whitespace-nowrap font-inter text-[15px] font-extrabold leading-none tracking-[0.14em] text-slate-50 transition-opacity duration-200"
                :class="expanded ? 'lg:opacity-100 lg:delay-150' : 'lg:pointer-events-none lg:opacity-0 lg:delay-0'"
            >UNK<span class="text-[#a855f7]">VOID</span></span>
        </a>

        <nav>
            <ul class="grid grid-cols-1 gap-1">
                @foreach ([
                    ['label' => 'Dashboard', 'icon' => 'layout-grid', 'route' => 'dashboard.index', 'pattern' => 'dashboard.*'],
                    ['label' => 'Meus vídeos', 'icon' => 'film', 'route' => 'videos.index', 'pattern' => 'videos.*'],
                    ['label' => 'Enviar vídeo', 'icon' => 'arrow-up-tray', 'route' => 'upload.index', 'pattern' => 'upload.*'],
                    ['label' => 'Biblioteca', 'icon' => 'film', 'route' => 'uploads.index', 'pattern' => 'uploads.*'],
                ] as $item)
                    <li class="group/item relative min-w-0">
                        <x-nav-item
                            :icon="$item['icon']"
                            :href="route($item['route'])"
                            :current="request()->routeIs($item['pattern'])"
                            x-on:mousemove="expand()"
                            x-on:click="collapseNow()"
                            ::class="expanded ? '' : 'lg:mx-auto lg:size-10 lg:justify-center lg:px-0'"
                        >
                            <span
                                class="whitespace-nowrap transition-opacity duration-200"
                                :class="expanded ? 'lg:opacity-100 lg:delay-150' : 'lg:w-0 lg:pointer-events-none lg:opacity-0 lg:delay-0'"
                            >{{ $item['label'] }}</span>
                        </x-nav-item>

                        <span
                            x-show="! expanded"
                            x-cloak
                            class="pointer-events-none absolute left-full top-1/2 z-50 ml-3 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-slate-700 bg-slate-800 px-2 py-1 text-xs font-medium text-slate-100 opacity-0 shadow-lg transition-opacity duration-150 group-hover/item:opacity-100 lg:block"
                        >{{ $item['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="flex-1"></div>

        @auth
            <div x-on:mousemove="expand()">
                <x-user-menu placement="top" collapsible />
            </div>
        @endauth
    </aside>
</div>
