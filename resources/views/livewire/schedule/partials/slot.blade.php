<div
    wire:key="slot-{{ $date }}-{{ $slot['editable'] ? 'd'.$slot['index'] : 'p'.$slot['id'] }}"
    @if ($slot['editable'])
        draggable="true"
        x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({ date: '{{ $date }}', index: {{ $slot['index'] }} }))"
        x-on:dragover.prevent
        x-on:drop.prevent="const d = JSON.parse($event.dataTransfer.getData('text/plain') || '{}'); if (d.date !== undefined && (d.date !== '{{ $date }}' || d.index !== {{ $slot['index'] }})) $wire.swapVideos(d.date, d.index, '{{ $date }}', {{ $slot['index'] }})"
    @endif
    @class([
        'flex flex-col gap-1.5 rounded-[11px] px-2.5 py-2 transition',
        $slot['containerClass'] => true,
        'cursor-grab active:cursor-grabbing' => $slot['editable'],
    ])
>
    <div class="flex items-center justify-between gap-1.5">
        <div class="flex items-center gap-1.5">
            @if ($slot['editable'])
                <input type="time" wire:model.blur="days.{{ $date }}.{{ $slot['index'] }}.time"
                    @class(['w-[84px] bg-transparent font-mono text-[13px] font-bold outline-none [color-scheme:dark]', $slot['timeClass'] => true]) />
            @else
                <span @class(['font-mono text-[13px] font-bold', $slot['timeClass'] => true])>{{ $slot['time'] }}</span>
            @endif
            <span @class(['size-1.5 shrink-0 rounded-full', $slot['dotClass'] => true])></span>
        </div>

        @if ($slot['editable'])
            <div class="flex items-center gap-1">
                <button type="button" wire:click="openPicker('{{ $date }}', {{ $slot['index'] }})" title="Editar horário/vídeo"
                    class="flex size-[18px] cursor-pointer items-center justify-center rounded-[5px] text-slate-500 transition hover:bg-sky-950/60 hover:text-sky-400">
                    <x-ui.icon name="pencil-square" class="size-3" />
                </button>
                <x-ui.toggle size="sm" :active="$slot['active']" title="Ativar/desativar horário"
                    wire:click="toggleSlot('{{ $date }}', {{ $slot['index'] }})" />
                <button type="button" wire:click="removeSlot('{{ $date }}', {{ $slot['index'] }})" title="Remover horário"
                    class="flex size-[18px] cursor-pointer items-center justify-center rounded-[5px] text-slate-500 transition hover:bg-red-950/60 hover:text-red-400">
                    <x-ui.icon name="x-mark" class="size-2.5" />
                </button>
            </div>
        @else
            <span title="Bloqueado" class="text-emerald-600/70">
                <x-ui.icon name="lock-closed" class="size-3" />
            </span>
        @endif
    </div>

    @if ($slot['title'])
        <div class="line-clamp-2 text-[11.5px] leading-snug text-slate-300">{{ $slot['title'] }}</div>
    @elseif ($slot['editable'] && $slot['status'] === 'empty' && $slot['active'])
        <button type="button" wire:click="openPicker('{{ $date }}', {{ $slot['index'] }})"
            class="flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-[7px] border border-dashed border-slate-600 px-2 py-1.5 text-[11.5px] font-semibold text-slate-400 transition hover:border-sky-400 hover:text-sky-400">
            <x-ui.icon name="play" class="size-3" />
            Buscar vídeo
        </button>
    @elseif ($slot['status'] === 'paused' || ($slot['status'] === 'empty' && ! $slot['active']))
        <div title="Ative o horário para agendar um vídeo"
            class="flex w-full cursor-not-allowed items-center justify-center gap-1.5 rounded-[7px] border border-dashed border-slate-700 px-2 py-1.5 text-[11px] font-semibold text-slate-600">
            <x-ui.icon name="lock-closed" class="size-3" />
            Postagem desativada
        </div>
    @endif

    @if ($slot['status'] === 'next')
        <span class="w-fit rounded-md bg-emerald-400 px-2 py-0.5 text-[10px] font-bold tracking-wider text-emerald-950">PRÓXIMO</span>
    @elseif ($slot['status'] === 'posting')
        <span class="flex items-center gap-1.5 text-[10px] font-bold tracking-wide text-sky-400">
            <x-ui.icon name="loading" class="size-3" />
            POSTANDO...
        </span>
    @elseif ($slot['status'] === 'posted')
        <span class="text-[10px] font-semibold tracking-wide text-emerald-600">Publicado</span>
    @elseif ($slot['status'] === 'skipped')
        <div class="flex flex-col gap-1.5">
            <span class="w-fit rounded-md bg-amber-400 px-2 py-0.5 text-[10px] font-bold tracking-wide text-amber-950">PULADO</span>
            @if ($slot['editable'] && $slot['id'] !== null && $slot['title'])
                <button type="button"
                    wire:click="forceDispatch({{ $slot['id'] }})"
                    wire:confirm="Disparar este slot agora nas plataformas habilitadas?"
                    class="flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-amber-400 px-2.5 py-1.5 text-[11.5px] font-bold text-amber-950 transition hover:brightness-110">
                    <x-ui.icon name="bolt" class="size-3" />
                    Forçar agora
                </button>
            @endif
        </div>
    @endif

    @if ($slot['platforms'] !== [] && in_array($slot['status'], ['failed', 'partial', 'posted'], true))
        <div x-data="{ showReason: false }" class="flex flex-col gap-1.5">
            @if ($slot['status'] !== 'posted')
                <div class="flex items-center gap-1.5">
                    <span @class([
                        'w-fit rounded-md px-2 py-0.5 text-[10px] font-bold tracking-wide',
                        'bg-amber-400 text-amber-950' => $slot['status'] === 'partial',
                        'bg-red-600 text-red-50' => $slot['status'] === 'failed',
                    ])>{{ $slot['status'] === 'partial' ? 'PARCIAL' : 'NÃO POSTADO' }}</span>
                    @if ($slot['hasFailures'])
                        <button type="button" x-on:click="showReason = !showReason"
                            class="cursor-pointer text-[11px] font-semibold text-red-400 underline">Ver motivo</button>
                    @endif
                </div>
            @endif

            <div x-show="showReason" x-cloak class="flex flex-col gap-1.5 rounded-lg border border-slate-700 bg-slate-900 px-2.5 py-2">
                @foreach ($slot['platforms'] as $platform)
                    <div class="flex flex-col gap-0.5">
                        <div class="flex items-center gap-1.5">
                            @if ($platform['ok'])
                                <x-ui.icon name="check" class="size-3 shrink-0 text-emerald-400" />
                            @elseif ($platform['pending'])
                                <x-ui.icon name="loading" class="size-3 shrink-0 text-sky-400" />
                            @else
                                <x-ui.icon name="x-circle" class="size-3 shrink-0 text-red-400" />
                            @endif
                            <span class="text-[11px] font-bold text-slate-200">{{ $platform['name'] }}</span>
                            <span @class([
                                'ml-auto text-[10px] font-semibold',
                                'text-emerald-400' => $platform['ok'],
                                'text-sky-400' => $platform['pending'],
                                'text-red-400' => ! $platform['ok'] && ! $platform['pending'],
                            ])>{{ $platform['ok'] ? 'Publicado' : ($platform['pending'] ? 'Postando' : 'Falhou') }}</span>
                        </div>
                        @if (! $platform['ok'] && ! $platform['pending'] && $platform['reason'])
                            <div class="pl-[18px] text-[10px] leading-snug text-red-300/80">{{ $platform['reason'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
