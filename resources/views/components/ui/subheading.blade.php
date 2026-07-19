@props(['size' => 'base'])

<p {{ $attributes->class(($size === 'lg' ? 'text-base' : 'text-sm').' text-slate-400') }}>{{ $slot }}</p>
