<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />

        <title>
            {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
        </title>

        <script>
            (() => {
                const apply = () => document.documentElement.classList.toggle('dark', (localStorage.theme ?? 'dark') === 'dark');
                apply();
                // wire:navigate faz morph do <html> com a marcacao do servidor (sem
                // a classe, que e client-side) — re-aplica apos cada navegacao SPA.
                document.addEventListener('livewire:navigated', apply);
            })();
        </script>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
        @if ($navbar)
            <x-navbar />
        @endif

        <main @class(['px-4 lg:px-8', 'py-6' => $navbar, 'py-4' => ! $navbar])>
            {{ $slot }}
        </main>

        <x-ui.toasts />

        @livewireScripts
    </body>
</html>
