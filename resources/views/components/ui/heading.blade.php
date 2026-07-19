@props(['size' => 'base'])

<h2 {{ $attributes->class(match ($size) {
    'xl' => 'text-2xl font-semibold text-slate-50',
    'lg' => 'text-lg font-semibold text-slate-50',
    default => 'text-base font-semibold text-slate-100',
}) }}>{{ $slot }}</h2>
