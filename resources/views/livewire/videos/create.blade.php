<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Entrada"
        title="Gerar cortes a partir de um vídeo"
        subtitle="Cole a URL do YouTube. O pipeline baixa, transcreve, abre a revisão e segue para o editor."
    />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_360px]">
        <x-studio.panel
            title="Nova ingestão"
            subtitle="Defina se o fluxo será manual ou totalmente automático antes de enviar para o processador."
        >
            <form wire:submit="start" class="flex flex-col gap-4">
                <flux:input
                    wire:model="url"
                    label="URL do vídeo"
                    type="url"
                    placeholder="https://www.youtube.com/watch?v=..."
                    required
                />

                <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                    <flux:field variant="inline">
                        <flux:checkbox wire:model.live="auto" />
                        <flux:label>Processo automático</flux:label>
                        <flux:description>
                            Transcreve, confirma, gera os cortes e já renderiza sem intervenção manual.
                        </flux:description>
                    </flux:field>
                </div>

                @if($auto)
                    <div class="grid gap-4 rounded-xl border border-slate-800 bg-slate-950/70 p-4 md:grid-cols-2">
                        <flux:select wire:model.live="clipMode" label="Como gerar os cortes">
                            <flux:select.option value="auto">Automático (decide pela duração)</flux:select.option>
                            <flux:select.option value="sequential">Clipes de 1 minuto (cobre o vídeo)</flux:select.option>
                            <flux:select.option value="ai">Recomendação da IA (melhores momentos)</flux:select.option>
                        </flux:select>

                        <flux:input
                            wire:model="clipCount"
                            type="number"
                            min="1"
                            max="60"
                            label="Quantidade de clipes (opcional)"
                            placeholder="Automático"
                            :description="$clipMode === 'sequential'
                                ? 'Máximo de clipes de ~1min a gerar. Vazio = cobre o vídeo inteiro.'
                                : ($clipMode === 'ai'
                                    ? 'Quantos cortes a IA deve escolher. Vazio = a IA decide.'
                                    : 'Limite de clipes. Vazio = automático.')"
                        />
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary" icon="arrow-down-tray" class="cursor-pointer">
                        <span wire:loading.remove wire:target="start">Iniciar processamento</span>
                        <span wire:loading wire:target="start">Enviando...</span>
                    </flux:button>
                    <p class="text-xs text-slate-500">Os vídeos novos entram na fila e atualizam o status automaticamente.</p>
                </div>
            </form>
        </x-studio.panel>

        <x-studio.panel
            title="Notas do fluxo"
            subtitle="Pontos operacionais que afetam a revisão e a geração de cortes."
        >
            <div class="space-y-3 text-sm text-slate-300">
                <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-3">
                    <p class="font-medium text-slate-100">1. Transcrição</p>
                    <p class="mt-1 text-slate-400">A revisão aparece uma única vez. Depois disso o fluxo segue direto para o editor.</p>
                </div>
                <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-3">
                    <p class="font-medium text-slate-100">2. Editor</p>
                    <p class="mt-1 text-slate-400">O corte é ajustado com timeline, playhead e sincronização por tempo.</p>
                </div>
                <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-3">
                    <p class="font-medium text-slate-100">3. Distribuição</p>
                    <p class="mt-1 text-slate-400">Após renderizar os cortes, o agendamento distribui por conta e plataforma.</p>
                </div>
            </div>
        </x-studio.panel>
    </div>

    @if($recent->isNotEmpty())
        <x-studio.panel
            title="Vídeos recentes"
            subtitle="Atalhos para voltar em ingestões abertas ou já processadas."
        >
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach($recent as $video)
                    <a
                        href="{{ route('videos.transcript', $video) }}"
                        wire:navigate
                        class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 transition hover:border-slate-700 hover:bg-slate-900"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-100">{{ $video->title ?? $video->url }}</p>
                                <p class="mt-1 truncate text-xs text-slate-500">{{ $video->uuid }}</p>
                            </div>
                            <flux:badge size="sm">{{ $video->status?->label ?? '—' }}</flux:badge>
                        </div>
                    </a>
                @endforeach
            </div>
        </x-studio.panel>
    @endif
</section>
