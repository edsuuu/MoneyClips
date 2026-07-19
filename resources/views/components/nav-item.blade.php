@props(['icon' => null, 'href', 'current' => false, 'size' => 'base'])

@php
    $sizeClasses = match ($size) {
        'lg' => [
            'item' => 'gap-3 px-3.5 py-3 text-base',
            'icon' => 'size-5',
        ],
        default => [
            'item' => 'gap-2 px-3 py-2 text-sm',
            'icon' => 'size-4',
        ],
    };
@endphp

<a
    href="{{ $href }}"
    wire:navigate
    {{ $attributes->class([
        'flex items-center rounded-lg transition',
        $sizeClasses['item'],
        'bg-slate-900 text-slate-50' => $current,
        'text-slate-300 hover:bg-slate-900 hover:text-slate-50' => ! $current,
    ]) }}
    @if($current) aria-current="page" @endif
>
    @if($icon)
        <x-ui.icon :name="$icon" class="{{ $sizeClasses['icon'] }} {{ $current ? 'text-slate-200' : 'text-slate-500' }}" />
    @endif
    {{ $slot }}
</a>
