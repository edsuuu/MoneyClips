@props([
    'label' => null,
    'description' => null,
    'rows' => 4,
])

@php
    $errorKey = $attributes->wire('model')->value() ?? $attributes->get('name');
@endphp

<div class="grid gap-2">
    @if($label)
        <label class="text-sm font-medium text-slate-200">{{ $label }}</label>
    @endif

    <textarea
        rows="{{ $rows }}"
        {{ $attributes->class('w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-400/40 disabled:opacity-60') }}
    >{{ $slot }}</textarea>

    @if($description)
        <p class="text-xs text-slate-400">{{ $description }}</p>
    @endif

    @if($errorKey)
        @error($errorKey)
            <p class="text-xs text-red-400">{{ $message }}</p>
        @enderror
    @endif
</div>
