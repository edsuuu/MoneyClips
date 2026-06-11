<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />

        <title>
            {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
        </title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
        @if($layout === 'sidebar')
            <x-sidebar />

            {{-- Top bar mobile: abre o drawer da sidebar --}}
            <div class="sticky top-0 z-20 flex items-center gap-3 border-b border-slate-800 bg-slate-950/95 px-4 py-3 backdrop-blur lg:hidden">
                <button type="button" class="cursor-pointer text-slate-300 hover:text-slate-50" x-data x-on:click="$dispatch('sidebar-toggle')">
                    <x-ui.icon name="bars-2" class="size-5" />
                </button>
                <x-app-logo href="{{ route('home') }}" wire:navigate />
            </div>

            <main class="px-4 py-6 lg:pl-72 lg:pr-8">
                {{ $slot }}
            </main>
        @elseif($layout === 'navbar')
            <x-sidebar layout="navbar" />

            <header class="sticky top-0 z-20 border-b border-slate-800 bg-slate-950/95 backdrop-blur">
                <div class="mx-auto flex h-14 w-full max-w-7xl items-center gap-4 px-4">
                    <button type="button" class="mr-1 cursor-pointer text-slate-300 hover:text-slate-50 lg:hidden" x-data x-on:click="$dispatch('sidebar-toggle')">
                        <x-ui.icon name="bars-2" class="size-5" />
                    </button>

                    <x-app-logo href="{{ route('home') }}" wire:navigate />

                    <nav class="-mb-px flex items-center gap-1 max-lg:hidden">
                        @auth
                            <x-nav-item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                                {{ __('Dashboard') }}
                            </x-nav-item>
                        @endauth

                        @if(request()->route('video'))
                            <x-nav-item icon="scissors" :href="route('videos.editor', request()->route('video'))" :current="request()->routeIs('videos.editor')">
                                {{ __('Editor') }}
                            </x-nav-item>
                        @endif
                    </nav>

                    <div class="flex-1"></div>

                    @auth
                        <x-user-menu class="w-56" />
                    @endauth

                    @guest
                        <x-nav-item :href="route('login')" :current="request()->routeIs('login')">
                            {{ __('Log in') }}
                        </x-nav-item>
                    @endguest
                </div>
            </header>

            <main>
                {{ $slot }}
            </main>
        @else
            <main>
                {{ $slot }}
            </main>
        @endif

        <x-ui.toasts />

        @livewireScripts
    </body>
</html>
