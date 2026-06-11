{{-- Item de navegação (sidebar/navbar) — substitui flux:navlist.item / flux:navbar.item --}}
@props(['icon' => null, 'href', 'current' => false])

<a
    href="{{ $href }}"
    wire:navigate
    {{ $attributes->class([
        'flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition',
        'bg-slate-900 text-slate-50' => $current,
        'text-slate-300 hover:bg-slate-900 hover:text-slate-50' => ! $current,
    ]) }}
    @if($current) aria-current="page" @endif
>
    @if($icon)
        <x-ui.icon :name="$icon" class="size-4 {{ $current ? 'text-slate-200' : 'text-slate-500' }}" />
    @endif
    {{ $slot }}
</a>
