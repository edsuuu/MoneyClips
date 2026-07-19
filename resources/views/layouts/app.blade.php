<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('layouts.head')
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
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

        <x-ui.toasts />

        @livewireScripts
    </body>
</html>
