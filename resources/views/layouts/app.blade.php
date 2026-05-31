<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        <title>
            {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
        </title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance

        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100">
        @if($layout === 'sidebar')
            <x-sidebar />
        @elseif($layout === 'navbar')
            <x-sidebar layout="navbar" />
            <flux:header container class="border-b border-slate-800 bg-slate-950/95 backdrop-blur">
                <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

                <x-app-logo href="{{ route('home') }}" wire:navigate />

                <flux:navbar class="-mb-px max-lg:hidden">
                    @auth
                        <flux:navbar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:navbar.item>
                    @endauth

                    @if(request()->route('video'))
                        <flux:navbar.item icon="document-text" :href="route('videos.transcript', request()->route('video'))" :current="request()->routeIs('videos.transcript')" wire:navigate>
                            {{ __('Transcrição') }}
                        </flux:navbar.item>

                        <flux:navbar.item icon="scissors" :href="route('videos.editor', request()->route('video'))" :current="request()->routeIs('videos.editor')" wire:navigate>
                            {{ __('Editor') }}
                        </flux:navbar.item>
                    @endif
                </flux:navbar>

                <flux:spacer />

                @auth
                    <flux:dropdown position="bottom" align="start">
                        <flux:sidebar.profile
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                            icon:trailing="chevrons-up-down"
                            data-test="sidebar-menu-button"
                        />

                        <flux:menu>
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />
                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                            <flux:menu.separator />
                            <flux:menu.radio.group>
                                <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                    {{ __('Settings') }}
                                </flux:menu.item>
                                <form method="POST" action="{{ route('logout') }}" class="w-full">
                                    @csrf
                                    <flux:menu.item
                                        as="button"
                                        type="submit"
                                        icon="arrow-right-start-on-rectangle"
                                        class="w-full cursor-pointer"
                                        data-test="logout-button"
                                    >
                                        {{ __('Log out') }}
                                    </flux:menu.item>
                                </form>
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>
                @endauth

                @guest
                    <div class="flex items-center gap-4">
                        <flux:navbar.item :href="route('login')" :current="request()->routeIs('login')" wire:navigate>
                            {{ __('Log in') }}
                        </flux:navbar.item>
                        <flux:navbar.item :href="route('register')" :current="request()->routeIs('register')" wire:navigate>
                            {{ __('Register') }}
                        </flux:navbar.item>
                    </div>
                @endguest
            </flux:header>
        @endif

        <flux:main class="bg-transparent">
            {{ $slot }}
        </flux:main>

        @livewireScripts
        @fluxScripts
    </body>
</html>
