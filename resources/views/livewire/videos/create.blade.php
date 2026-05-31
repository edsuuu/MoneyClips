<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Entrada"
        title="Gerar cortes a partir de um vídeo"
        subtitle="Escolha primeiro como os cortes serão criados. Depois a ingestão segue com o fluxo correto."
    />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_360px]">
        <x-studio.panel
            title="Nova ingestão"
            subtitle="Defina o modo antes de enviar para o processador."
        >
            <form wire:submit="start" class="flex flex-col gap-4">
                <flux:input
                    wire:model="url"
                    label="URL do vídeo"
                    type="url"
                    placeholder="https://www.youtube.com/watch?v=..."
                    required
                />

                <div class="space-y-3">
                    <div class="text-sm font-medium text-slate-100">Como você quer gerar os cortes?</div>

                    <div class="grid gap-3 md:grid-cols-3">
                        <button
                            type="button"
                            wire:click="$set('processingMode', 'manual')"
                            class="rounded-2xl border p-4 text-left transition {{ $processingMode === 'manual' ? 'border-sky-500 bg-sky-500/10' : 'border-slate-800 bg-slate-950/70 hover:border-slate-700 hover:bg-slate-900' }}"
                        >
                            <div class="text-sm font-semibold text-slate-100">Manual</div>
                            <p class="mt-2 text-xs text-slate-400">
                                Baixa, transcreve e abre a revisão antes de você criar os cortes no editor.
                            </p>
                        </button>

                        <button
                            type="button"
                            wire:click="$set('processingMode', 'sequential')"
                            class="rounded-2xl border p-4 text-left transition {{ $processingMode === 'sequential' ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/70 hover:border-slate-700 hover:bg-slate-900' }}"
                        >
                            <div class="text-sm font-semibold text-slate-100">Automático 60s</div>
                            <p class="mt-2 text-xs text-slate-400">
                                Divide o vídeo inteiro em clipes sequenciais de 60 segundos.
                            </p>
                        </button>

                        <button
                            type="button"
                            wire:click="$set('processingMode', 'ai')"
                            class="rounded-2xl border p-4 text-left transition {{ $processingMode === 'ai' ? 'border-fuchsia-500 bg-fuchsia-500/10' : 'border-slate-800 bg-slate-950/70 hover:border-slate-700 hover:bg-slate-900' }}"
                        >
                            <div class="text-sm font-semibold text-slate-100">IA escolhe</div>
                            <p class="mt-2 text-xs text-slate-400">
                                A IA escolhe os melhores momentos e respeita fronteiras naturais de fala.
                            </p>
                        </button>
                    </div>
                </div>

                @if($processingMode === 'sequential')
                    <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-4 text-sm text-slate-300">
                        <p class="font-medium text-slate-100">Cobertura automática por duração</p>
                        <p class="mt-2 text-slate-400">
                            Exemplo: se o vídeo tiver <span class="text-slate-200">4:39</span>, o sistema gera
                            <span class="text-slate-200">5 clipes</span>: 4 clipes de 60 segundos e 1 clipe final de 39 segundos.
                        </p>
                    </div>
                @endif

                @if($processingMode === 'ai')
                    <div class="grid gap-4 rounded-2xl border border-fuchsia-500/30 bg-fuchsia-500/5 p-4 md:grid-cols-[minmax(0,220px)_1fr]">
                        <flux:input
                            wire:model="clipCount"
                            type="number"
                            min="1"
                            max="60"
                            label="Quantidade de clipes"
                            placeholder="Ex.: 4, 6 ou 10"
                        />

                        <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3 text-xs text-slate-400">
                            <p class="font-medium text-slate-100">Como a IA decide</p>
                            <p class="mt-2">
                                O projeto Python <code>auto-post</code> já instrui a IA a escolher momentos que
                                façam sentido sozinhos, com clímax/resolução, e a evitar corte no meio de frase.
                            </p>
                            <p class="mt-2">
                                Se deixar vazio, a IA decide a quantidade dentro da faixa padrão da aplicação.
                            </p>
                        </div>
                    </div>
                @endif

                <div class="flex flex-col gap-2 rounded-2xl border border-slate-800 bg-slate-950/70 p-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-slate-100">Seguir o rosto nos cortes</p>
                        <p class="mt-1 text-xs text-slate-400">
                            Liga o face tracking + active speaker detection (crop dinâmico 9:16 que
                            segue quem está falando). Desligue para screencast, animação ou vídeo
                            sem rosto — fica mais rápido e o crop vira centralizado fixo.
                        </p>
                    </div>
                    <flux:switch wire:model="faceTracking" />
                </div>

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
            title="Como cada modo funciona"
            subtitle="Resumo objetivo do comportamento da automação."
        >
            <div class="space-y-3 text-sm text-slate-300">
                <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-3">
                    <p class="font-medium text-slate-100">Manual</p>
                    <p class="mt-1 text-slate-400">Mantém revisão de transcrição e criação de cortes por você.</p>
                </div>
                <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-3">
                    <p class="font-medium text-slate-100">Automático 60s</p>
                    <p class="mt-1 text-slate-400">Cobre o vídeo inteiro em blocos sequenciais e já segue para renderização.</p>
                </div>
                <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-3">
                    <p class="font-medium text-slate-100">IA escolhe</p>
                    <p class="mt-1 text-slate-400">Usa o analisador do <code>auto-post</code> com quantidade alvo de cortes e foco em falas completas.</p>
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
