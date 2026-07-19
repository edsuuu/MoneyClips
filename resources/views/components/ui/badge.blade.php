@props([
    'color' => 'zinc', // zinc | green | red | amber | blue | cyan | sky | pink | purple | lime
    'size' => 'base',  // sm | base
])

@php
    $classes = collect([
        'inline-flex items-center gap-1 rounded-full border font-medium',
        $size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-0.5 text-xs',
        match ($color) {
            'green' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
            'red' => 'border-red-500/30 bg-red-500/10 text-red-300',
            'amber' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
            'blue' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',
            'cyan' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',
            'sky' => 'border-sky-500/30 bg-sky-500/10 text-sky-300',
            'pink' => 'border-pink-500/30 bg-pink-500/10 text-pink-300',
            'purple' => 'border-purple-500/30 bg-purple-500/10 text-purple-300',
            'lime' => 'border-lime-500/30 bg-lime-500/10 text-lime-300',
            default => 'border-slate-700 bg-slate-800/70 text-slate-300',
        },
    ])->implode(' ');
@endphp

<span {{ $attributes->class($classes) }}>{{ $slot }}</span>
