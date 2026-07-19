@props(['label' => null])

<label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-200">
    <input
        type="checkbox"
        {{ $attributes->class('size-4 cursor-pointer rounded border-slate-600 bg-slate-900 text-slate-200 accent-slate-300 focus:ring-slate-400/40') }}
    />
    @if($label)<span>{{ $label }}</span>@endif
</label>
