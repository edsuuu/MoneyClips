<a {{ $attributes->class('flex items-center gap-2') }}>
    <span class="flex aspect-square size-8 items-center justify-center rounded-md bg-slate-100 text-slate-950">
        <x-app-logo-icon class="size-5 fill-current" />
    </span>
    <span class="truncate text-sm font-semibold text-slate-100">{{ config('app.name', 'Generate Clips') }}</span>
</a>
