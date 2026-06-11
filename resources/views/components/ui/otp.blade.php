{{-- Campo de código OTP (TOTP/2FA) — substitui flux:otp --}}
@props(['length' => 6, 'label' => null])

<div class="grid justify-items-center gap-2">
    @if($label)
        <label class="sr-only">{{ $label }}</label>
    @endif

    <input
        type="text"
        inputmode="numeric"
        autocomplete="one-time-code"
        pattern="[0-9]*"
        maxlength="{{ $length }}"
        placeholder="{{ str_repeat('•', (int) $length) }}"
        {{ $attributes->class('w-48 rounded-lg border border-slate-700 bg-slate-900 px-3 py-2.5 text-center font-mono text-xl tracking-[0.5em] text-slate-100 placeholder:text-slate-600 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-400/40') }}
    />
</div>
