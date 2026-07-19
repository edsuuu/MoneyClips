<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark uv-surface">
    <head>
        @include('layouts.head')
    </head>
    {{-- Fundo e tipografia do design (Inter): valem só nas telas públicas —
         o app autenticado continua slate-950 + Instrument Sans. --}}
    <body class="min-h-screen bg-[#0a0a0f] font-inter text-[#ededed] antialiased">
        {{-- Navbar da landing: só marca + âncoras + CTA. Nada do menu do app
             aqui (isso vive na sidebar do layout autenticado). --}}
        <header class="sticky top-0 z-20 border-b border-[#191022] bg-[#0a0a0f]/92 backdrop-blur">
            <div class="mx-auto flex h-[68px] w-full max-w-[1120px] items-center justify-between gap-6 px-6">
                <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2.5">
                    <x-brand-mark class="size-9 drop-shadow-[0_0_9px_rgba(124,58,237,0.55)]" />
                    <span class="text-[19px] font-extrabold tracking-[0.14em] text-slate-50">UNK<span class="text-[#a855f7]">VOID</span></span>
                </a>

                {{-- Âncoras com a rota completa: funcionam também a partir das
                     telas legal/auth, que usam este mesmo layout. --}}
                <nav class="hidden items-center gap-8 text-sm text-[#a1a1aa] lg:flex">
                    <a href="{{ route('home') }}#funcoes" class="transition hover:text-white">Funcionalidades</a>
                    <a href="{{ route('home') }}#como-funciona" class="transition hover:text-white">Como funciona</a>
                    <a href="{{ route('home') }}#precos" class="transition hover:text-white">Preços</a>
                </nav>

                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('videos.index') }}" wire:navigate class="rounded-full bg-[linear-gradient(120deg,#7c3aed,#a855f7)] px-[18px] py-[9px] text-sm font-semibold text-white shadow-[0_6px_20px_rgba(124,58,237,0.4)] transition hover:brightness-110">Ir para o app</a>
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

        {{-- Login em modal central: substitui a tela /login (que só redireciona
             pra cá com ?login=1). Cores da landing + foco no e-mail ao abrir. --}}
        @guest
            <x-ui.modal
                name="login"
                class="border-[#2a1840]! bg-[#0b0710]!"
                {{-- setTimeout (e não $nextTick): o foco só funciona depois do
                     x-show tirar o display:none, inclusive na abertura automática. --}}
                x-on:modal-show.window="setTimeout(() => $el.querySelector('input[type=email]')?.focus(), 60)"
            >
                <livewire:auth.login />
            </x-ui.modal>

            @if ($openLogin)
                <div x-data x-init="$nextTick(() => $dispatch('modal-show', { name: 'login' }))"></div>
            @endif
        @endguest

        <x-ui.toasts />

        @livewireScripts
    </body>
</html>
