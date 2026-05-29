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

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 2xl:grid-cols-3">
        @forelse($cards as $card)
            @php($video = $card['video'])
            @php($statusKey = $video->status?->key ?? '')
            @php($statusColor = match ($statusKey) {
                'completed', 'full_subtitled', 'waiting_cuts' => 'green',
                'failed' => 'red',
                'processing', 'downloading', 'recommending_cuts', 'cutting', 'subtitling_full' => 'blue',
                default => 'zinc',
            })
            @php($duration = is_numeric($video->duration_seconds) ? (int) round((float) $video->duration_seconds) : null)
            <article class="overflow-hidden rounded-3xl border border-slate-800 bg-[radial-gradient(circle_at_top,_rgba(148,163,184,0.10),_rgba(15,23,42,0.95)_52%)] shadow-[0_20px_60px_rgba(2,6,23,0.35)] transition hover:border-slate-700">
                <a href="{{ route('videos.editor', $video) }}" wire:navigate class="group block cursor-pointer">
                    <div class="relative aspect-video bg-slate-950">
                        @if($card['thumb'])
                            <video src="{{ $card['thumb'] }}" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]" muted playsinline preload="metadata"></video>
                        @else
                            <div class="flex h-full w-full items-center justify-center text-xs text-slate-500">Sem preview</div>
                        @endif

                        <div class="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between gap-3 p-3">
                            <flux:badge color="{{ $statusColor }}" size="sm">{{ $video->status?->label ?? '—' }}</flux:badge>
                            <div class="rounded-full bg-slate-950/80 px-2.5 py-1 text-[11px] tabular-nums text-slate-300 backdrop-blur">
                                {{ $video->created_at?->format('d/m H:i') }}
                            </div>
                        </div>

                        <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950 via-slate-950/60 to-transparent p-3">
                            <div class="flex items-center gap-2 text-[11px] uppercase tracking-[0.16em] text-slate-300">
                                <span>{{ $video->source_provider ?? 'video' }}</span>
                                @if($duration !== null)
                                    <span class="text-slate-500">•</span>
                                    <span>{{ sprintf('%d:%02d', intdiv($duration, 60), $duration % 60) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </a>

                <div class="space-y-4 p-4">
                    <div class="space-y-2">
                        <p class="line-clamp-2 text-base font-semibold leading-snug text-slate-100">
                            {{ $video->title ?? 'Vídeo sem título' }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span class="rounded-full border border-slate-800 bg-slate-950/70 px-2 py-1 font-mono">
                                {{ $video->uuid }}
                            </span>
                            @if($video->progress !== null && $statusKey !== 'completed')
                                <span>{{ (int) $video->progress }}% concluído</span>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <flux:button :href="route('videos.editor', $video)" variant="primary" size="sm" class="cursor-pointer justify-center" wire:navigate>
                            Abrir editor
                        </flux:button>
                        <flux:button :href="route('videos.schedule', $video)" variant="filled" size="sm" class="cursor-pointer justify-center" wire:navigate>
                            Agendar
                        </flux:button>

                        @if($video->status?->key === 'failed')
                            <flux:button variant="subtle" size="sm" icon="arrow-path" class="col-span-2 cursor-pointer justify-center" wire:click="reprocess('{{ $video->uuid }}')" wire:loading.attr="disabled" wire:target="reprocess('{{ $video->uuid }}')">
                                Reprocessar
                            </flux:button>
                        @endif
                    </div>

                    @if($pendingDeleteUuid === $video->uuid)
                        <div class="rounded-2xl border border-red-500/30 bg-red-500/10 p-3">
                            <p class="text-sm font-medium text-red-100">Excluir este vídeo?</p>
                            <p class="mt-1 text-xs text-red-200/80">
                                Isso remove vídeo, transcrição, cortes, jobs e arquivos vinculados.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:button variant="danger" size="sm" class="cursor-pointer" wire:click="confirmDelete">
                                    Confirmar exclusão
                                </flux:button>
                                <flux:button variant="ghost" size="sm" class="cursor-pointer" wire:click="cancelDelete">
                                    Cancelar
                                </flux:button>
                            </div>
                        </div>
                    @else
                        <div class="border-t border-slate-800 pt-3">
                            <flux:button variant="ghost" size="sm" class="w-full cursor-pointer justify-center text-red-300 hover:text-red-200" wire:click="askDelete('{{ $video->uuid }}')">
                                Excluir vídeo
                            </flux:button>
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <div class="col-span-full rounded-xl border border-slate-800 bg-slate-900/70 p-8 text-center text-slate-500">
                Nenhum vídeo encontrado.
            </div>
        @endforelse
    </div>
</section>
