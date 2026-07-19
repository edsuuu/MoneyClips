@auth
    <a href="{{ route('dashboard.index') }}" wire:navigate {{ $attributes }}>{{ $slot }}</a>
@else
    <button type="button" x-data x-on:click="$dispatch('modal-show', { name: 'login' })" {{ $attributes }}>{{ $slot }}</button>
@endauth
