{{-- Item de menu de dropdown — substitui flux:menu.item --}}
@props(['icon' => null, 'href' => null, 'type' => null])

@php
    $classes = 'flex w-full cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-300 transition hover:bg-slate-800 hover:text-slate-50';
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if($icon)<x-ui.icon :name="$icon" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type ?? 'button' }}" {{ $attributes->class($classes) }}>
        @if($icon)<x-ui.icon :name="$icon" />@endif
        {{ $slot }}
    </button>
@endif
