@props([
    'close',
    'title' => null,
    'maxWidth' => 'max-w-md',
])

<div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" wire:click="{{ $close }}"></div>

    <div {{ $attributes->class(["relative flex max-h-[88vh] w-full {$maxWidth} flex-col overflow-hidden rounded-2xl border border-slate-800 bg-slate-900 shadow-2xl shadow-black/50"]) }}>
        @if ($title)
            <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                <span class="text-base font-bold">{{ $title }}</span>
                <button type="button" wire:click="{{ $close }}"
                    class="flex size-7 cursor-pointer items-center justify-center rounded-lg text-slate-500 hover:bg-slate-800 hover:text-slate-200">
                    <x-ui.icon name="x-mark" class="size-4" />
                </button>
            </div>
        @endif

        {{ $slot }}

        @isset($footer)
            <div class="flex justify-end gap-2 border-t border-slate-800 px-5 py-3.5">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
