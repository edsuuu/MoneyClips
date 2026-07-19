@props([
    'variant' => 'info', // info | warning | danger | success
    'icon' => null,
    'heading' => null,
])

@php
    $tone = match ($variant) {
        'warning' => 'border-amber-500/30 bg-amber-500/10 text-amber-200',
        'danger' => 'border-red-500/30 bg-red-500/10 text-red-200',
        'success' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200',
        default => 'border-sky-500/30 bg-sky-500/10 text-sky-200',
    };
@endphp

<div {{ $attributes->class("flex gap-3 rounded-xl border p-4 text-sm {$tone}") }}>
    @if($icon)
        <x-ui.icon :name="$icon" class="mt-0.5 size-5" />
    @endif
    <div class="space-y-1">
        @if($heading)
            <p class="font-semibold">{{ $heading }}</p>
        @endif
        <div class="leading-6 opacity-90">{{ $slot }}</div>
    </div>
</div>
