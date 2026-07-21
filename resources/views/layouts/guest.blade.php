<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="uv-surface">
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
    <body class="min-h-screen bg-[#0a0a0f] font-inter text-[#ededed] antialiased">
        <header class="sticky top-0 z-20 border-b border-[#191022] bg-[#0a0a0f]/92 backdrop-blur">
            <div class="mx-auto flex h-[68px] w-full max-w-[1120px] items-center justify-between gap-6 px-6">
                <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2.5">
                    <x-brand-mark class="size-9 drop-shadow-[0_0_9px_rgba(124,58,237,0.55)]" />
                    <span class="text-[19px] font-extrabold tracking-[0.14em] text-slate-50">UNK<span class="text-[#a855f7]">VOID</span></span>
                </a>

                <nav class="hidden items-center gap-8 text-sm text-[#a1a1aa] lg:flex">
                    <a href="{{ route('home') }}#funcoes" class="transition hover:text-white">Funcionalidades</a>
                    <a href="{{ route('home') }}#como-funciona" class="transition hover:text-white">Como funciona</a>
                    <a href="{{ route('home') }}#precos" class="transition hover:text-white">Preços</a>
                </nav>

                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('dashboard.index') }}" wire:navigate class="rounded-full bg-[linear-gradient(120deg,#7c3aed,#a855f7)] px-[18px] py-[9px] text-sm font-semibold text-white shadow-[0_6px_20px_rgba(124,58,237,0.4)] transition hover:brightness-110">Ir para o app</a>
                    @endauth

                    @guest
                        <x-login-cta class="cursor-pointer rounded-full bg-[linear-gradient(120deg,#7c3aed,#a855f7)] px-[18px] py-[9px] text-sm font-semibold text-white shadow-[0_6px_20px_rgba(124,58,237,0.4)] transition hover:brightness-110">Começar grátis</x-login-cta>
                    @endguest
                </div>
            </div>
        </header>

        <main @class(['mx-auto w-full max-w-[1400px] px-4 pb-16 pt-8 sm:px-8 2xl:px-11' => ! $bleed])>
            {{ $slot }}
        </main>

        @guest
            <x-ui.modal
                name="login"
                max-width="max-w-lg"
                class="border-[#2a1840]! bg-[#0b0710]!"
                x-on:modal-show.window="setTimeout(() => $el.querySelector('input[type=email]')?.focus(), 60)"
            >
                <div x-data="{ tab: 'login' }" class="flex flex-col gap-6">
                    <div class="grid grid-cols-2 gap-1 rounded-xl border border-[#241830] bg-[#141018] p-1">
                        <button
                            type="button"
                            x-on:click="tab = 'login'"
                            class="cursor-pointer rounded-lg py-2 text-sm font-semibold transition"
                            :class="{ 'bg-[linear-gradient(120deg,#7c3aed,#a855f7)] text-white shadow-[0_6px_18px_rgba(124,58,237,0.35)]': tab === 'login', 'text-[#a1a1aa] hover:text-white': tab !== 'login' }"
                        >
                            {{ __('Log in') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="tab = 'register'"
                            class="cursor-pointer rounded-lg py-2 text-sm font-semibold transition"
                            :class="{ 'bg-[linear-gradient(120deg,#7c3aed,#a855f7)] text-white shadow-[0_6px_18px_rgba(124,58,237,0.35)]': tab === 'register', 'text-[#a1a1aa] hover:text-white': tab !== 'register' }"
                        >
                            {{ __('Register') }}
                        </button>
                    </div>

                    <div x-show="tab === 'login'">
                        <livewire:auth.login />
                    </div>

                    <div x-show="tab === 'register'" x-cloak>
                        <livewire:auth.register />
                    </div>
                </div>
            </x-ui.modal>

            <x-ui.modal
                name="forgot-password"
                max-width="max-w-lg"
                class="border-[#2a1840]! bg-[#0b0710]!"
                x-on:modal-show.window="setTimeout(() => $el.querySelector('input[type=email]')?.focus(), 60)"
            >
                <livewire:auth.forgot-password />
            </x-ui.modal>

            @if ($openLogin)
                <div x-data x-init="$nextTick(() => $dispatch('modal-show', { name: 'login' }))"></div>
            @endif
        @endguest

        <x-ui.toasts />

        @livewireScripts
    </body>
</html>
