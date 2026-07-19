@props([
    'variant' => 'outline', // primary | filled | ghost | subtle | danger | outline
    'size' => 'base',       // xs | sm | base
    'icon' => null,
    'iconTrailing' => null,
    'href' => null,
    'type' => 'button',
])

@php
    $classes = collect([
        'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition cursor-pointer',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400/70',
        'disabled:pointer-events-none disabled:opacity-50',
        match ($size) {
            'xs' => 'px-2 py-1 text-xs',
            'sm' => 'px-2.5 py-1.5 text-sm',
            default => 'px-3.5 py-2 text-sm',
        },
        match ($variant) {
            'primary' => 'bg-slate-100 text-slate-950 hover:bg-white',
            'filled' => 'bg-slate-800 text-slate-100 hover:bg-slate-700',
            'ghost' => 'text-slate-300 hover:text-slate-50',
            'subtle' => 'text-slate-300 hover:bg-slate-800/80 hover:text-slate-50',
            'danger' => 'bg-red-600 text-white hover:bg-red-500',
            default => 'border border-slate-700 bg-slate-900 text-slate-200 hover:border-slate-600 hover:bg-slate-800',
        },
    ])->implode(' ');

    $iconSize = $size === 'xs' ? 'size-3.5' : 'size-4';
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if($icon)<x-ui.icon :name="$icon" class="{{ $iconSize }}" />@endif
        {{ $slot }}
        @if($iconTrailing)<x-ui.icon :name="$iconTrailing" class="{{ $iconSize }}" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if($icon)<x-ui.icon :name="$icon" class="{{ $iconSize }}" />@endif
        {{ $slot }}
        @if($iconTrailing)<x-ui.icon :name="$iconTrailing" class="{{ $iconSize }}" />@endif
    </button>
@endif
