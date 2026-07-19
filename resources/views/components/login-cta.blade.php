@auth
    <a href="{{ route('videos.index') }}" wire:navigate {{ $attributes }}>{{ $slot }}</a>
@else
    <button type="button" x-data x-on:click="$dispatch('modal-show', { name: 'login' })" {{ $attributes }}>{{ $slot }}</button>
@endauth
