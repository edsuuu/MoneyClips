@props([
    'label',
    'value',
    'tone' => 'slate',
])

@php
    $tones = [
        'slate' => 'bg-slate-500/10 text-slate-300 ring-slate-500/20',
        'blue' => 'bg-blue-500/10 text-blue-300 ring-blue-500/20',
        'green' => 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/20',
        'amber' => 'bg-amber-500/10 text-amber-300 ring-amber-500/20',
        'red' => 'bg-rose-500/10 text-rose-300 ring-rose-500/20',
    ];
@endphp

<div {{ $attributes->class('rounded-xl border border-slate-800 bg-slate-950/60 p-4') }}>
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-2xl font-semibold tabular-nums text-slate-50">{{ $value }}</div>
            <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.16em] text-slate-400">{{ $label }}</div>
        </div>

        <span class="inline-flex h-2.5 w-2.5 rounded-full ring-4 {{ $tones[$tone] ?? $tones['slate'] }}"></span>
    </div>
</div>
