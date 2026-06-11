{{-- Avatar por iniciais — substitui flux:avatar --}}
@props(['initials' => '', 'name' => null])

<span {{ $attributes->class('flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-800 text-xs font-semibold uppercase text-slate-200') }} @if($name) title="{{ $name }}" @endif>
    {{ $initials }}
</span>
