{{-- Modal com Alpine — substitui flux:modal.
     Uso por eventos: $dispatch('modal-show', { name }) / $dispatch('modal-close', { name })
     Ou controlado pelo Livewire via wire:model="propriedadeBooleana". --}}
@props([
    'name' => null,
    'maxWidth' => 'max-w-md',
])

@php
    $wireModel = $attributes->wire('model')->value();
    $attributes = $attributes->whereDoesntStartWith('wire:model');
@endphp

<div
    @if($wireModel)
        x-data="{ open: $wire.entangle('{{ $wireModel }}').live }"
    @else
        x-data="{ open: false }"
    @endif
    @if($name)
        x-on:modal-show.window="if ($event.detail.name === '{{ $name }}') open = true"
        x-on:modal-close.window="if ($event.detail.name === '{{ $name }}') open = false"
    @endif
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
>
    <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" x-on:click="open = false"></div>

    <div
        x-show="open"
        x-transition.origin.center
        {{ $attributes->class("relative max-h-[90vh] w-full {$maxWidth} overflow-y-auto rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl shadow-black/50") }}
    >
        {{ $slot }}
    </div>
</div>
