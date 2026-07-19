@props([
    'active' => false,
    'size' => 'base', // sm | base
])

<button
    type="button"
    {{ $attributes->class([
        'relative shrink-0 cursor-pointer rounded-full transition',
        'h-4 w-7' => $size === 'sm',
        'h-6 w-10' => $size !== 'sm',
        'bg-emerald-500' => $active,
        'bg-slate-700' => ! $active,
    ]) }}
>
    <span @class([
        'absolute rounded-full bg-white transition-all',
        'top-0.5 size-3' => $size === 'sm',
        'left-[15px]' => $size === 'sm' && $active,
        'left-0.5' => $size === 'sm' && ! $active,
        'top-[3px] size-[18px]' => $size !== 'sm',
        'left-[19px]' => $size !== 'sm' && $active,
        'left-[3px]' => $size !== 'sm' && ! $active,
    ])></span>
</button>
