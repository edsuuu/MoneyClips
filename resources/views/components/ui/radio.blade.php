@props(['label' => null, 'description' => null])

<label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-800 bg-slate-900/60 p-3 text-sm transition hover:border-slate-600 has-checked:border-slate-400 has-checked:bg-slate-800/80">
    <input
        type="radio"
        {{ $attributes->class('mt-0.5 size-4 cursor-pointer border-slate-600 bg-slate-900 accent-slate-300 focus:ring-slate-400/40') }}
    />
    <span class="grid gap-0.5">
        @if($label)<span class="font-medium text-slate-100">{{ $label }}</span>@endif
        @if($description)<span class="text-xs text-slate-400">{{ $description }}</span>@endif
    </span>
</label>
