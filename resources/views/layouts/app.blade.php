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
        <x-sidebar />

        <div class="sticky top-0 z-20 flex items-center gap-3 border-b border-slate-800 bg-slate-950/95 px-4 py-3 backdrop-blur lg:hidden">
            <button type="button" class="cursor-pointer text-slate-300 hover:text-slate-50" x-data x-on:click="$dispatch('sidebar-toggle')">
                <x-ui.icon name="bars-2" class="size-5" />
            </button>
            <x-app-logo href="{{ route('home') }}" wire:navigate />
        </div>

        <main class="px-4 py-6 lg:pl-24 lg:pr-8">
            {{ $slot }}
        </main>

        <x-ui.toasts />

        @livewireScripts
    </body>
</html>
