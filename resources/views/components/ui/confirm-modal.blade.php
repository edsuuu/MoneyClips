@props([
    'title',
    'description' => null,
    'icon' => 'exclamation-triangle',
    'maxWidth' => 'max-w-lg',
])

<x-ui.modal :max-width="$maxWidth" {{ $attributes }}>
    <div class="space-y-6">
        <div class="flex gap-4">
            @if($icon)
                <div class="flex size-11 shrink-0 items-center justify-center rounded-xl border border-slate-700 bg-slate-950 text-slate-200">
                    <x-ui.icon :name="$icon" class="size-5" />
                </div>
            @endif

            <div class="min-w-0 space-y-1">
                <h2 class="text-lg font-semibold text-slate-50">{{ $title }}</h2>

                @if($description)
                    <p class="text-sm leading-6 text-slate-400">{{ $description }}</p>
                @endif
            </div>
        </div>

        @if(trim((string) $slot) !== '')
            <div>
                {{ $slot }}
            </div>
        @endif

        @isset($actions)
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                {{ $actions }}
            </div>
        @endisset
    </div>
</x-ui.modal>
