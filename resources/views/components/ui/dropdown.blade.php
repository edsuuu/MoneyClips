{{-- Dropdown com Alpine — substitui flux:dropdown + flux:menu --}}
@props(['align' => 'start', 'placement' => 'bottom']) {{-- align: start | end, placement: top | bottom --}}

@php
    $panelPosition = $placement === 'top' ? 'bottom-full mb-2' : 'top-full mt-2';
@endphp

<div
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
    {{ $attributes->class('relative') }}
>
    <div x-on:click="open = !open" class="cursor-pointer">
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-transition
        class="absolute z-40 w-60 rounded-xl border border-slate-800 bg-slate-900 p-1.5 shadow-xl shadow-black/40 {{ $panelPosition }} {{ $align === 'end' ? 'right-0' : 'left-0' }}"
    >
        {{ $slot }}
    </div>
</div>
