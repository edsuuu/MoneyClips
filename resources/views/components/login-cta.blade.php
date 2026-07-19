{{-- CTA de entrada da landing. Visitante abre o modal de login do layout
     público (não existe tela /login); quem já está logado vai direto pro app.
     Estilo vem por classe de quem chama — aqui só mora o comportamento. --}}
@auth
    <a href="{{ route('videos.index') }}" wire:navigate {{ $attributes }}>{{ $slot }}</a>
@else
    <button type="button" x-data x-on:click="$dispatch('modal-show', { name: 'login' })" {{ $attributes }}>{{ $slot }}</button>
@endauth
