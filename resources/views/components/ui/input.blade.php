{{-- Campo de texto com label e erro de validação — substitui flux:input --}}
@props([
    'label' => null,
    'description' => null,
    'type' => 'text',
    'viewable' => false, // senha com botão de mostrar/ocultar
])

@php
    // Resolve a chave de erro a partir do wire:model ou do name.
    $errorKey = $attributes->wire('model')->value() ?? $attributes->get('name');
@endphp

<div class="grid gap-2" @if($viewable) x-data="{ reveal: false }" @endif>
    @if($label)
        <label class="text-sm font-medium text-slate-200">
            {{ $label }}
        </label>
    @endif

    <div class="relative">
        <input
            @if($viewable)
                x-bind:type="reveal ? 'text' : 'password'"
            @else
                type="{{ $type }}"
            @endif
            {{ $attributes->class('w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-400/40 disabled:opacity-60'.($viewable ? ' pr-10' : '')) }}
        />

        @if($viewable)
            <button
                type="button"
                x-on:click="reveal = !reveal"
                class="absolute inset-y-0 right-0 flex cursor-pointer items-center px-3 text-slate-400 hover:text-slate-200"
                tabindex="-1"
            >
                <x-ui.icon name="eye" x-show="!reveal" />
                <x-ui.icon name="eye-slash" x-show="reveal" x-cloak />
            </button>
        @endif
    </div>

    @if($description)
        <p class="text-xs text-slate-400">{{ $description }}</p>
    @endif

    @if($errorKey)
        @error($errorKey)
            <p class="text-xs text-red-400">{{ $message }}</p>
        @enderror
    @endif
</div>
