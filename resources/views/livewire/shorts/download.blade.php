<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Auto-postagem"
        title="Baixar Shorts de um canal"
        subtitle="Envie a URL do canal: o microserviço baixa os Shorts, sobe para o storage e mantém tudo no banco dele até você decidir despachar."
    >
        <x-slot:actions>
            <x-ui.button :href="route('shorts.index')" size="sm" variant="subtle" icon="bolt" class="cursor-pointer" wire:navigate>
                Estoque de Shorts
            </x-ui.button>
        </x-slot:actions>
    </x-studio.page-header>

    <x-studio.panel title="Novo download" subtitle="Todos os Shorts do canal serão baixados em lote.">
        <form wire:submit="start" class="flex flex-col gap-4 md:flex-row md:items-end">
            <div class="flex-1">
                <x-ui.input
                    wire:model="channelUrl"
                    label="URL do canal"
                    type="url"
                    placeholder="https://www.youtube.com/@canal"
                    required
                />
            </div>
            <x-ui.button type="submit" variant="primary" icon="arrow-down-tray" class="cursor-pointer">
                <span wire:loading.remove wire:target="start">Baixar Shorts</span>
                <span wire:loading wire:target="start">Enviando...</span>
            </x-ui.button>
        </form>
    </x-studio.panel>

    @if($jobId !== null)
        <div @if($isRunning) wire:poll.5s="refreshStatus" @endif>
            <x-studio.panel title="Progresso do job" subtitle="Job {{ $jobId }}">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        @if($state === '')
                            <x-ui.badge color="zinc" size="sm">Consultando...</x-ui.badge>
                        @elseif(in_array($state, ['queued', 'listing'], true))
                            <x-ui.badge color="blue" size="sm">Listando Shorts do canal...</x-ui.badge>
                        @elseif($state === 'processing')
                            <x-ui.badge color="amber" size="sm">Baixando e enviando ao storage...</x-ui.badge>
                        @elseif($state === 'completed')
                            <x-ui.badge color="green" size="sm">Concluído</x-ui.badge>
                        @elseif($state === 'completed_partial')
                            <x-ui.badge color="amber" size="sm">Concluído com falhas</x-ui.badge>
                        @else
                            <x-ui.badge color="red" size="sm">Falhou</x-ui.badge>
                        @endif

                        @if($isRunning)
                            <span class="text-xs text-slate-500">atualizando a cada 5s</span>
                        @endif
                    </div>

                    <x-ui.button wire:click="clearJob" size="xs" variant="subtle" icon="x-mark" class="cursor-pointer">
                        Limpar
                    </x-ui.button>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <x-studio.metric-card label="Total" :value="$counts['total']" tone="zinc" />
                    <x-studio.metric-card label="Na fila" :value="$counts['pending']" tone="zinc" />
                    <x-studio.metric-card label="Processando" :value="$counts['processing']" tone="blue" />
                    <x-studio.metric-card label="Baixados" :value="$counts['completed']" tone="green" />
                    <x-studio.metric-card label="Falhas" :value="$counts['failed']" tone="red" />
                </div>

                @if($lastError !== '')
                    <div class="mt-4 rounded-xl border border-red-900/60 bg-red-950/40 p-3 text-xs text-red-300">
                        Último erro: {{ $lastError }}
                    </div>
                @endif

                @unless($isRunning)
                    <p class="mt-4 text-xs text-slate-500">
                        Os vídeos ficam no storage e os metadados no banco do microserviço
                        (<code class="rounded bg-slate-900 px-1 py-0.5">short_download_items</code>) até serem despachados.
                    </p>
                @endunless
            </x-studio.panel>
        </div>
    @endif
</section>
