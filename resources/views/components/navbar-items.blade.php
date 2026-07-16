{{-- Itens da navbar (design docs/designs/*.dc.html): item ativo tem borda
     inferior sky. Dados prontos em App\View\Components\NavbarItems. --}}
<nav {{ $attributes->class('flex h-full items-center gap-1') }}>
    @foreach ($items as $item)
        <a
            href="{{ route($item['route']) }}"
            wire:navigate
            @class([
                'flex h-full items-center gap-2 border-b-2 px-3.5 text-sm font-semibold transition',
                'border-sky-400 text-slate-50' => $item['current'],
                'border-transparent text-slate-400 hover:text-slate-100' => ! $item['current'],
            ])
            @if($item['current']) aria-current="page" @endif
        >
            <x-ui.icon :name="$item['icon']" class="size-4" />
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
