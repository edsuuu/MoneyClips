@props(['layout' => 'sidebar'])

{{-- Sidebar fixa no desktop; no mobile vira um drawer controlado por Alpine
     (evento global `sidebar-toggle`, disparado pelo botão do header). --}}
<div
    x-data="{ open: false }"
    x-on:sidebar-toggle.window="open = !open"
    x-on:keydown.escape.window="open = false"
>
    {{-- Overlay mobile --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-30 bg-slate-950/70 lg:hidden" x-on:click="open = false"></div>

    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-slate-800 bg-slate-950/95 p-4 text-slate-100 backdrop-blur transition-transform duration-200 lg:translate-x-0 {{ $layout === 'navbar' ? 'lg:hidden' : '' }}"
        :class="open ? 'translate-x-0' : ''"
    >
        <button type="button" class="mb-2 cursor-pointer self-end text-slate-400 hover:text-slate-100 lg:hidden" x-on:click="open = false">
            <x-ui.icon name="x-mark" class="size-5" />
        </button>

        <div class="px-2">
            <x-app-logo href="{{ route('home') }}" wire:navigate />
            <div class="mt-3 rounded-xl border border-slate-800 bg-slate-900/70 p-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Generate Clips</p>
            </div>
        </div>

        <nav class="mt-6 px-2">
            <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Workspace</p>
            <ul class="grid gap-1">
                @auth
                    <li>
                        <x-nav-item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-nav-item>
                    </li>
                @endauth
                <li>
                    <x-nav-item icon="film" :href="route('videos.index')" :current="request()->routeIs('videos.index')">
                        {{ __('Vídeos') }}
                    </x-nav-item>
                </li>
                <li>
                    <x-nav-item icon="calendar-days" :href="route('posts.dashboard')" :current="request()->routeIs('posts.dashboard')">
                        {{ __('Publicações') }}
                    </x-nav-item>
                </li>
                <li>
                    <x-nav-item icon="film" :href="route('downloads.index')" :current="request()->routeIs('downloads.index')">
                        {{ __('Downloads') }}
                    </x-nav-item>
                </li>
                <li>
                    <x-nav-item icon="computer-desktop" :href="route('microservices.index')" :current="request()->routeIs('microservices.index')">
                        {{ __('Microserviços') }}
                    </x-nav-item>
                </li>
            </ul>
        </nav>

        <div class="flex-1"></div>

        @auth
            <x-user-menu placement="top" />
        @endauth
    </aside>
</div>
