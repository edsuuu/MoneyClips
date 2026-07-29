<section class="flex w-full flex-col gap-4">
    <div class="flex max-h-[calc(100vh-130px)] min-h-[480px] flex-col overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
        <div class="flex flex-col gap-3 border-b border-slate-800 px-4 py-3.5">
            <div class="flex items-center gap-2.5">
                <span class="text-sm font-bold">Stream de logs</span>
                <div class="ml-auto flex items-center gap-2 rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-1.5">
                    <x-ui.icon name="activity" class="size-3.5 text-slate-500" />
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Buscar no log..."
                        class="w-40 bg-transparent text-[12.5px] text-slate-100 outline-none placeholder:text-slate-600" />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2.5">
                <div class="flex max-w-full gap-1 overflow-x-auto rounded-[9px] border border-slate-700 bg-slate-950 p-[3px]">
                    @foreach ($serviceOptions as $option)
                        <button type="button" wire:click="setServiceFilter('{{ $option }}')" wire:key="svc-opt-{{ $option }}"
                            @class([
                                'cursor-pointer whitespace-nowrap rounded-[7px] px-3 py-1.5 font-mono text-xs font-semibold transition',
                                'bg-slate-700 text-slate-50' => $serviceFilter === $option,
                                'text-slate-400 hover:text-slate-200' => $serviceFilter !== $option,
                            ])>
                            {{ $option === 'all' ? 'Todos' : $option }}
                        </button>
                    @endforeach
                </div>

                <div class="flex gap-1 rounded-[9px] border border-slate-700 bg-slate-950 p-[3px]">
                    @foreach (['all' => 'Todos', 'info' => 'Info', 'warn' => 'Warn', 'error' => 'Error'] as $level => $label)
                        <button type="button" wire:click="setLevelFilter('{{ $level }}')" wire:key="lvl-opt-{{ $level }}"
                            @class([
                                'flex cursor-pointer items-center gap-1.5 rounded-[7px] px-3 py-1.5 text-xs font-bold transition',
                                'bg-slate-700 text-slate-50' => $levelFilter === $level,
                                'text-slate-400 hover:text-slate-200' => $levelFilter !== $level,
                            ])>
                            {{ $label }}
                            @if ($level === 'error' && $errorCount > 0)
                                <span class="rounded-full bg-red-600 px-1.5 text-[10px] font-extrabold text-red-50">{{ $errorCount }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto" wire:poll.3s>
            @forelse ($logs as $log)
                <button type="button" wire:click="openDetail({{ $log['id'] }})" wire:key="log-{{ $log['id'] }}"
                    @class([
                        'flex w-full cursor-pointer items-start gap-3 border-b border-slate-800/60 px-4 py-2 text-left transition hover:bg-slate-800/60',
                        'bg-red-950/20' => $log['isError'],
                    ])>
                    <span class="shrink-0 pt-0.5 font-mono text-xs text-slate-500">{{ $log['time'] }}</span>
                    <x-log-level-badge :level="$log['level']" class="w-[52px] shrink-0" />
                    <span @class(['w-[130px] shrink-0 truncate font-mono text-[11.5px] font-bold', $log['colorClass'] => true])>{{ $log['service'] }}</span>
                    <span class="min-w-0 flex-1 break-words font-mono text-[12.5px] leading-relaxed text-slate-200">{{ $log['message'] }}</span>
                    @if ($log['hasContext'])
                        <x-ui.icon name="chevron-right" class="mt-0.5 size-3.5 shrink-0 text-red-400/70" />
                    @endif
                </button>
            @empty
                <div class="px-5 py-16 text-center text-slate-500">
                    <x-ui.icon name="activity" class="mx-auto mb-3 size-8 opacity-60" />
                    <div class="text-[13.5px]">Nenhum log nos filtros selecionados</div>
                    <div class="mt-1 text-xs text-slate-600">Os microserviços enviam logs automaticamente quando o OBSERVABILITY_TOKEN está configurado.</div>
                </div>
            @endforelse
        </div>
    </div>

    @if ($detail)
        <div class="fixed inset-0 z-50 flex justify-end bg-slate-950/70" wire:click="closeDetail">
            <div class="flex h-full w-[560px] max-w-[94vw] flex-col border-l border-slate-800 bg-slate-900 shadow-2xl shadow-black/60" wire:click.stop>
                <div class="flex items-center gap-3 border-b border-slate-800 px-5 py-4">
                    <x-log-level-badge :level="$detail['level']" class="px-2.5 text-[11px]" />
                    <span @class(['font-mono text-[13px] font-bold', $detail['serviceColor'] => true])>{{ $detail['service'] }}</span>
                    <span class="ml-auto font-mono text-xs text-slate-500">{{ $detail['time'] }}</span>
                    <button type="button" wire:click="closeDetail" class="flex size-7 cursor-pointer items-center justify-center rounded-lg text-slate-500 hover:bg-slate-800 hover:text-slate-200">
                        <x-ui.icon name="x-mark" class="size-4" />
                    </button>
                </div>

                <div class="flex flex-1 flex-col gap-5 overflow-y-auto p-5">
                    <div>
                        <div class="mb-2 font-mono text-[10.5px] tracking-[0.08em] text-slate-500">MENSAGEM</div>
                        <div @class([
                            'break-words rounded-[9px] border border-slate-700 border-l-[3px] bg-slate-950 px-3.5 py-3 font-mono text-[13.5px] leading-relaxed',
                            'border-l-red-500 text-red-200' => $detail['isError'],
                            'border-l-sky-400 text-slate-100' => ! $detail['isError'],
                        ])>{{ $detail['message'] }}</div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        @foreach ($detail['fields'] as $key => $value)
                            <div class="rounded-[9px] border border-slate-800 bg-slate-950 px-3 py-2.5" wire:key="detail-field-{{ $key }}">
                                <div class="mb-1 font-mono text-[9.5px] tracking-wide text-slate-500">{{ $key }}</div>
                                <div class="break-words font-mono text-[12.5px] font-semibold text-slate-200">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>

                    @if ($detail['contextJson'])
                        <div>
                            <div class="mb-2 flex items-center gap-2">
                                <x-ui.icon name="exclamation-triangle" class="size-3.5 text-red-400" />
                                <span class="font-mono text-[10.5px] tracking-[0.08em] text-red-400">CONTEXTO / STACK TRACE</span>
                            </div>
                            <pre class="overflow-x-auto rounded-[9px] border border-red-500/30 bg-slate-950 p-3.5 font-mono text-[11.5px] leading-relaxed text-slate-300">{{ $detail['contextJson'] }}</pre>
                        </div>
                    @endif

                    <div class="flex gap-2">
                        <button type="button" wire:click="filterDetailService"
                            class="flex cursor-pointer items-center gap-2 rounded-[9px] border border-slate-700 px-3.5 py-2 text-[12.5px] font-semibold text-sky-400 transition hover:border-sky-400">
                            Ver só deste serviço
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</section>
