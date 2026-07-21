@props([
    'variant' => 'info', // info | warning | danger | success
    'icon' => null,
    'heading' => null,
])

<div {{ $attributes->class('flex gap-3 rounded-xl border p-4 text-sm')->class([
    'border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-200' => $variant === 'warning',
    'border-red-500/30 bg-red-500/10 text-red-800 dark:text-red-200' => $variant === 'danger',
    'border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200' => $variant === 'success',
    'border-sky-500/30 bg-sky-500/10 text-sky-800 dark:text-sky-200' => ! in_array($variant, ['warning', 'danger', 'success'], true),
]) }}>
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
