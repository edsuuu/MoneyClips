<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Biblioteca"
        title="Vídeos"
        subtitle="Acompanhe tudo o que já entrou no pipeline e abra direto no editor ou no agendamento."
    >
        <x-slot:actions>
            <flux:button :href="route('videos.create')" variant="primary" icon="plus" class="cursor-pointer" wire:navigate>
                Novo vídeo
            </flux:button>
        </x-slot:actions>
    </x-studio.page-header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @forelse($cards as $card)
            @php($video = $card['video'])
            <article class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900/70">
                <a href="{{ route('videos.editor', $video) }}" wire:navigate class="block cursor-pointer">
                    <div class="aspect-video bg-slate-950">
                        @if($card['thumb'])
                            <video src="{{ $card['thumb'] }}" class="h-full w-full object-cover" muted playsinline preload="metadata"></video>
                        @else
                            <div class="flex h-full w-full items-center justify-center text-xs text-slate-500">Sem preview</div>
                        @endif
                    </div>
                </a>
                <div class="space-y-3 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <p class="line-clamp-2 text-sm font-medium text-slate-100">{{ $video->title ?? 'Vídeo sem título' }}</p>
                        <span class="shrink-0 text-[11px] tabular-nums text-slate-500">{{ $video->created_at?->format('d/m H:i') }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <flux:badge size="sm">{{ $video->status?->label ?? '—' }}</flux:badge>
                        <span class="text-xs text-slate-500">{{ $video->uuid }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button :href="route('videos.editor', $video)" variant="subtle" size="xs" class="cursor-pointer" wire:navigate>
                            Editor
                        </flux:button>
                        <flux:button :href="route('videos.schedule', $video)" variant="ghost" size="xs" class="cursor-pointer" wire:navigate>
                            Agendar
                        </flux:button>
                        @if($pendingDeleteUuid === $video->uuid)
                            <flux:button variant="danger" size="xs" class="cursor-pointer" wire:click="confirmDelete">
                                Confirmar
                            </flux:button>
                            <flux:button variant="ghost" size="xs" class="cursor-pointer" wire:click="cancelDelete">
                                Cancelar
                            </flux:button>
                        @else
                            <flux:button variant="danger" size="xs" class="cursor-pointer" wire:click="askDelete('{{ $video->uuid }}')">
                                Excluir
                            </flux:button>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="col-span-full rounded-xl border border-slate-800 bg-slate-900/70 p-8 text-center text-slate-500">
                Nenhum vídeo encontrado.
            </div>
        @endforelse
    </div>
</section>
