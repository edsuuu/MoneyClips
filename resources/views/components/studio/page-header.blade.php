@props([
    'title',
    'subtitle' => null,
    'eyebrow' => null,
])

<div {{ $attributes->class('flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between') }}>
    <div class="min-w-0 space-y-2">
        @if($eyebrow)
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">
                {{ $eyebrow }}
            </p>
        @endif

        <div class="space-y-1">
            <h1 class="text-2xl font-semibold text-slate-50">
                {{ $title }}
            </h1>

            @if($subtitle)
                <p class="max-w-3xl text-sm leading-6 text-slate-400">
                    {{ $subtitle }}
                </p>
            @endif
        </div>

        @if(isset($meta))
            <div class="flex flex-wrap items-center gap-2">
                {{ $meta }}
            </div>
        @endif
    </div>

    @if(isset($actions))
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
