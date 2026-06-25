<section class="w-full" @if($autoRefresh) wire:poll.5s @endif>
    <div class="relative mb-6 w-full">
        <div class="flex items-center justify-between gap-4">
            <div>
                <x-ui.heading size="xl" level="1">Microserviços</x-ui.heading>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.checkbox wire:model.live="autoRefresh" label="Auto-atualizar (5s)" />
                <x-ui.button variant="subtle" size="sm" wire:click="refresh">
                    <span wire:loading.remove wire:target="refresh">Atualizar</span>
                    <span wire:loading wire:target="refresh">Atualizando…</span>
                </x-ui.button>
            </div>
        </div>
        <x-ui.separator variant="subtle" class="mt-4" />
    </div>

    {{-- Cards de status --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($statuses as $service)
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-base font-semibold text-slate-100">{{ $service['label'] }}</div>
                    </div>
                    <x-ui.badge :color="$service['up'] ? 'green' : 'red'" size="sm" class="min-h-7 px-3">
                        {{ $service['up'] ? 'Online' : 'Offline' }}
                    </x-ui.badge>
                </div>

                @if($service['docker'])
                    <button
                        type="button"
                        wire:click="selectLog('{{ $service['docker'] }}')"
                        @class([
                            'mt-4 inline-flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition',
                            'border-slate-100 bg-slate-100 text-slate-950' => $logService === $service['docker'],
                            'border-slate-700 bg-slate-900 text-slate-300 hover:border-slate-500 hover:bg-slate-800 hover:text-slate-100' => $logService !== $service['docker'],
                        ])
                    >
                        <x-ui.icon name="document-text" class="size-3.5" />
                        <span>{{ $logService === $service['docker'] ? 'Vendo logs' : 'Ver logs' }}</span>
                    </button>
                @else
                    <div class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
                        <x-ui.icon name="command-line" class="size-3.5" />
                        Logs no terminal do host
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- "Terminal" de logs --}}
    <div class="mt-6">
        <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-medium text-slate-200">
                Logs: <span class="font-mono text-slate-400">{{ $logService }}</span>
            </div>
            <div class="flex items-center gap-2 text-xs text-slate-400">
                <span>linhas</span>
                <select wire:model.live="logLines" class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1 text-slate-200 focus:outline-none">
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="500">500</option>
                    <option value="1000">1000</option>
                </select>
            </div>
        </div>

        <div
            wire:loading.class="opacity-50"
            x-data="{
                cleanupScrollHook: null,
                scrollToBottom() {
                    this.$nextTick(() => {
                        this.$el.scrollTop = this.$el.scrollHeight;
                    });
                },
                init() {
                    this.scrollToBottom();

                    this.cleanupScrollHook = window.Livewire?.hook('morphed', ({ el }) => {
                        if (! this.$el.isConnected || ! el.contains(this.$el)) {
                            return;
                        }

                        this.scrollToBottom();
                    });
                },
                destroy() {
                    this.cleanupScrollHook?.();
                },
            }"
            class="mt-2 h-[28rem] overflow-auto rounded-2xl border border-slate-800 bg-black/80 p-4 font-mono text-xs leading-relaxed text-emerald-200/90"
            data-test="microservices-logs"
        >
            <pre class="whitespace-pre-wrap break-words">{{ $logs }}</pre>
        </div>
    </div>
</section>
