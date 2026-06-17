<div class="flex w-full flex-col gap-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-50">Postagem instantânea</h2>
            <p class="text-sm text-slate-400">Sorteie um vídeo do estoque e envie para YouTube/TikTok.</p>
        </div>
        <x-ui.button wire:click="pickRandom" variant="primary" icon="sparkles" size="sm" class="shrink-0 cursor-pointer">
            Pegar aleatório
        </x-ui.button>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-studio.metric-card label="No estoque" :value="$counts['stock']" tone="blue" />
        <x-studio.metric-card label="YouTube postados" :value="$counts['youtubePosted']" tone="green" />
        <x-studio.metric-card label="TikTok em fila" :value="$counts['tiktokQueued']" tone="amber" />
        <x-studio.metric-card label="TikTok postados" :value="$counts['tiktokPosted']" tone="green" />
    </div>

    <x-studio.panel title="Vídeo selecionado" subtitle="O sorteio usa os registros de youtube_shorts com arquivo no storage.">
        @if(! $short)
            <div class="rounded-lg border border-dashed border-slate-800 p-10 text-center text-sm text-slate-400">
                Nenhum vídeo selecionado. Clique em <span class="font-medium text-slate-200">Pegar vídeo aleatório</span>.
            </div>
        @else
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="line-clamp-2 text-lg font-semibold text-slate-50">{{ $short->title ?? $short->youtube_id }}</p>
                            <a
                                href="https://www.youtube.com/shorts/{{ $short->youtube_id }}"
                                target="_blank"
                                rel="noopener"
                                class="mt-1 inline-flex text-xs text-slate-500 hover:text-slate-300"
                            >{{ $short->youtube_id }}</a>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if($short->wasPostedToYoutube())
                                <x-ui.badge color="green" size="sm">YouTube postado</x-ui.badge>
                            @else
                                <x-ui.badge color="zinc" size="sm">YouTube disponível</x-ui.badge>
                            @endif

                            @if($tiktokStatus?->status === 'completed')
                                <x-ui.badge color="green" size="sm">TikTok postado</x-ui.badge>
                            @elseif(in_array($tiktokStatus?->status, ['queued', 'processing'], true))
                                <x-ui.badge color="amber" size="sm">TikTok em fila</x-ui.badge>
                            @elseif($tiktokStatus?->status === 'failed')
                                <x-ui.badge color="red" size="sm">TikTok falhou</x-ui.badge>
                            @else
                                <x-ui.badge color="zinc" size="sm">TikTok disponível</x-ui.badge>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 text-sm text-slate-400 md:grid-cols-2">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-600">Arquivo</p>
                            <p class="mt-1 truncate text-slate-300">{{ $short->video_path ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-600">Baixado em</p>
                            <p class="mt-1 text-slate-300">{{ $short->downloaded_at?->format('d/m/Y H:i') ?? '—' }}</p>
                        </div>
                    </div>

                    <div class="mt-4 text-sm text-slate-400">
                        {{ implode(' ', $short->hashtags ?? []) ?: 'Sem hashtags cadastradas.' }}
                    </div>
                </div>

                <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-4">
                    <p class="text-sm font-medium text-slate-100">Enviar para</p>
                    <div class="mt-4 grid gap-3">
                        <x-ui.checkbox
                            wire:model="postYoutube"
                            label="YouTube"
                            :disabled="$short->wasPostedToYoutube() || ! $youtubeReady"
                        />
                        <x-ui.checkbox
                            wire:model="postTiktok"
                            label="TikTok"
                            :disabled="in_array($tiktokStatus?->status, ['queued', 'processing', 'completed', 'dry-run'], true)"
                        />
                    </div>

                    @unless($youtubeReady)
                        <p class="mt-3 text-xs text-amber-300">Configure uma conta YouTube ativa antes de postar.</p>
                    @endunless

                    <x-ui.button
                        wire:click="postSelected"
                        wire:confirm="Enviar o vídeo selecionado para as plataformas marcadas?"
                        variant="primary"
                        icon="paper-airplane"
                        class="mt-5 w-full cursor-pointer"
                    >
                        Postar selecionado
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-studio.panel>
</div>
