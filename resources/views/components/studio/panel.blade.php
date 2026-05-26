@props([
    'title' => null,
    'subtitle' => null,
    'padding' => 'p-4',
])

<section {{ $attributes->class("rounded-xl border border-slate-800 bg-slate-900/70 backdrop-blur-sm {$padding}") }}>
    @if($title || $subtitle || isset($actions))
        <div class="mb-4 flex flex-col gap-3 border-b border-slate-800/80 pb-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-1">
                @if($title)
                    <h2 class="text-sm font-semibold text-slate-100">{{ $title }}</h2>
                @endif

                @if($subtitle)
                    <p class="text-xs leading-5 text-slate-400">{{ $subtitle }}</p>
                @endif
            </div>

            @if(isset($actions))
                <div class="flex flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endif
        </div>
    @endif

    {{ $slot }}
</section>
