<section class="flex w-full flex-col gap-5">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-6">
        <div>
            <div class="flex flex-wrap items-center gap-3.5">
                <h1 class="text-[32px] font-extrabold tracking-tight text-slate-50">Agenda</h1>

                @if ($nextDispatch)
                    <div class="flex items-center gap-2 rounded-full border border-slate-800 bg-slate-900 px-3 py-1.5">
                        <span class="size-1.5 rounded-full bg-emerald-400"></span>
                        <span class="text-xs text-slate-400">Próximo disparo</span>
                        <span class="text-xs font-bold text-slate-100">{{ $nextDispatch['label'] }}</span>
                        <span class="font-mono text-[11px] text-slate-500">· {{ $nextDispatch['in'] }}</span>
                    </div>
                @endif
            </div>
            <p class="mt-2 text-xs text-slate-500">
                Semana de <span class="font-semibold text-sky-400">{{ $weekRangeLabel }}</span>
                · arraste para trocar vídeos entre slots, clique num slot vazio para agendar.
            </p>
        </div>

        <div class="flex items-start gap-3">
            <div class="flex rounded-[10px] border border-slate-800 bg-slate-900 p-[3px]">
                <button type="button" wire:click="setView('week')"
                    @class([
                        'cursor-pointer rounded-lg px-4 py-1.5 text-[13px] font-semibold transition',
                        'bg-slate-700 text-slate-50' => $view === 'week',
                        'text-slate-400 hover:text-slate-200' => $view !== 'week',
                    ])>
                    Semana
                </button>
                <button type="button" wire:click="setView('month')"
                    @class([
                        'cursor-pointer rounded-lg px-4 py-1.5 text-[13px] font-semibold transition',
                        'bg-slate-700 text-slate-50' => $view === 'month',
                        'text-slate-400 hover:text-slate-200' => $view !== 'month',
                    ])>
                    Mês
                </button>
            </div>

            @unless ($locked)
                <div class="flex flex-col items-end gap-1.5">
                    <x-ui.button wire:click="save" wire:loading.attr="disabled">Salvar agenda</x-ui.button>
                    @if ($dirty)
                        <span class="flex items-center gap-1.5 font-mono text-[11.5px] text-amber-400">
                            <span class="size-1.5 rounded-full bg-amber-400"></span>
                            alterações não salvas
                        </span>
                    @endif
                </div>
            @endunless
        </div>
    </div>

    {{-- Toggles por plataforma --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($platforms as $platform)
            <button
                type="button"
                wire:key="platform-{{ $platform['platform'] }}"
                @if ($platform['implemented']) wire:click="togglePlatform('{{ $platform['platform'] }}')" @else title="Em breve" @endif
                @class([
                    'flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition',
                    'cursor-pointer border-emerald-500/40 bg-emerald-950/30 text-emerald-300' => $platform['implemented'] && $platform['enabled'],
                    'cursor-pointer border-slate-700 bg-slate-900 text-slate-400 hover:text-slate-200' => $platform['implemented'] && ! $platform['enabled'],
                    'cursor-not-allowed border-slate-800 bg-slate-900/50 text-slate-600' => ! $platform['implemented'],
                ])
            >
                <span @class([
                    'size-1.5 rounded-full',
                    'bg-emerald-400' => $platform['enabled'],
                    'bg-slate-600' => ! $platform['enabled'],
                ])></span>
                {{ $platform['name'] }}
                @unless ($platform['implemented'])
                    <span class="font-mono text-[9px] uppercase tracking-wider text-slate-600">breve</span>
                @endunless
            </button>
        @endforeach
    </div>

    {{-- Abas de semana + gerador --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="flex gap-1.5 rounded-xl border border-slate-800 bg-slate-900/70 p-1.5">
            @foreach ($weekTabs as $tab)
                <button type="button" wire:click="selectWeek({{ $tab['offset'] }})" wire:key="week-tab-{{ $tab['offset'] }}"
                    @class([
                        'flex min-w-28 cursor-pointer flex-col items-start gap-0.5 rounded-[9px] border px-3.5 py-2 transition',
                        'border-sky-500/50 bg-sky-950/40' => $tab['selected'],
                        'border-transparent hover:bg-slate-800/70' => ! $tab['selected'],
                    ])>
                    <span @class([
                        'text-[13px] font-bold',
                        'text-slate-50' => $tab['selected'],
                        'text-slate-400' => ! $tab['selected'],
                    ])>{{ $tab['label'] }}</span>
                    <span class="font-mono text-[10px] text-slate-500">{{ $tab['rangeLabel'] }}</span>
                </button>
            @endforeach
        </div>

        @unless ($locked)
            <div class="ml-auto flex items-center gap-2.5">
                <span class="text-xs text-slate-400">Vídeos/dia</span>
                <div class="flex items-center gap-0.5 rounded-[9px] border border-slate-700 bg-slate-900 p-[3px]">
                    <button type="button" wire:click="decPerDay" class="flex size-6 cursor-pointer items-center justify-center rounded-md text-slate-300 hover:bg-slate-800">−</button>
                    <span class="w-6 text-center font-mono text-sm font-bold">{{ $videosPerDay }}</span>
                    <button type="button" wire:click="incPerDay" class="flex size-6 cursor-pointer items-center justify-center rounded-md text-slate-300 hover:bg-slate-800">+</button>
                </div>
                <button type="button" wire:click="generateWeek" wire:loading.attr="disabled"
                    class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-violet-500/50 bg-violet-950/40 px-4 py-2 text-[13px] font-semibold text-violet-300 transition hover:bg-violet-950/60 disabled:opacity-60">
                    <x-ui.icon name="sparkles" class="size-3.5" />
                    Gerar semana
                </button>
            </div>
        @endunless
    </div>

    @if ($locked)
        <div class="flex items-center gap-2.5 rounded-xl border border-slate-800 bg-slate-900 px-4 py-3">
            <x-ui.icon name="lock-closed" class="size-4 text-emerald-500/70" />
            <span class="text-[13px] text-slate-400">Semana concluída — <strong class="text-slate-200">bloqueada para edição</strong>.</span>
        </div>
    @endif

    {{-- Kanban semanal --}}
    @if ($view === 'week')
        <div class="flex items-start gap-3 overflow-x-auto pb-2.5">
            @foreach ($boardDays as $day)
                <div class="flex max-h-[640px] w-[222px] shrink-0 flex-col overflow-hidden rounded-[14px] border border-slate-800 bg-slate-900" wire:key="day-{{ $day['dateString'] }}">
                    {{-- Cabeçalho da coluna --}}
                    <div @class([
                        'flex items-center justify-between border-b border-slate-800 px-4 py-3.5',
                        'bg-sky-950/30' => $day['isToday'],
                    ])>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-[15px] font-bold">{{ $day['name'] }}</span>
                                @if ($day['isToday'])
                                    <span class="rounded-[5px] bg-sky-400/15 px-1.5 py-0.5 text-[9.5px] font-bold tracking-wider text-sky-400">HOJE</span>
                                @endif
                            </div>
                            <div class="mt-0.5 font-mono text-[11px] text-slate-500">{{ $day['date']->format('d/m') }}</div>
                        </div>
                        <div class="text-right">
                            <div class="font-mono text-[10px] text-slate-500">POSTADOS</div>
                            <div class="text-[13px] font-bold">{{ $day['postedCount'] }}<span class="font-medium text-slate-500">/{{ $day['total'] }}</span></div>
                        </div>
                    </div>

                    {{-- Slots --}}
                    <div class="flex flex-col gap-2 overflow-y-auto p-2.5">
                        @foreach ($day['slots'] as $slot)
                            @include('livewire.schedule.partials.slot', ['slot' => $slot, 'date' => $day['dateString']])
                        @endforeach

                        @if ($day['canAdd'])
                            <button type="button" wire:click="addSlot('{{ $day['dateString'] }}')"
                                class="flex cursor-pointer items-center justify-center gap-1.5 rounded-[10px] border-[1.5px] border-dashed border-slate-700 py-2 text-[13px] font-semibold text-slate-500 transition hover:border-sky-400 hover:text-sky-400">
                                <x-ui.icon name="plus" class="size-3.5" />
                                Horário
                            </button>
                        @elseif ($day['showMaxNotice'])
                            <div class="rounded-lg bg-slate-800/70 py-1.5 text-center text-[10.5px] font-semibold text-slate-500">
                                Máx. {{ $maxPerDay }} horários/dia
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Legenda --}}
        <div class="flex flex-wrap gap-5 text-xs text-slate-500">
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-400"></span> Publicado</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-sky-400"></span> Postando</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-400"></span> Pulado / parcial</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-500"></span> Não postado</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full border-[1.5px] border-slate-600"></span> Vazio</span>
        </div>
    @endif

    {{-- Visão mês --}}
    @if ($view === 'month' && $monthData)
        <div>
            <div class="mb-3.5">
                <div class="font-mono text-[11px] tracking-[0.08em] text-slate-400">{{ $monthData['label'] }}</div>
                <div class="mt-1 text-[13px] text-slate-500">Passe o mouse sobre um dia para ver os horários e vídeos agendados.</div>
            </div>

            <div class="mb-2 grid grid-cols-7 gap-2">
                @foreach ($weekdayLabels as $label)
                    <div class="text-center text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $label }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7 gap-2">
                @foreach ($monthData['cells'] as $cell)
                    <div class="min-h-[104px]" wire:key="cell-{{ $cell['date'] }}">
                        @if ($cell['real'])
                            <div x-data="{ hovered: false }" x-on:mouseenter="hovered = true" x-on:mouseleave="hovered = false"
                                @class([
                                    'relative flex h-full flex-col gap-1.5 rounded-[11px] border bg-slate-900 p-2 transition hover:border-sky-500/50',
                                    'border-sky-500/40' => $cell['isToday'],
                                    'border-slate-800' => ! $cell['isToday'],
                                ])>
                                <div class="flex items-center justify-between">
                                    <span @class([
                                        'text-[13px] font-bold',
                                        'text-sky-400' => $cell['isToday'],
                                        'text-slate-200' => ! $cell['isToday'],
                                    ])>{{ $cell['dnum'] }}</span>
                                    @if ($cell['isToday'])
                                        <span class="rounded bg-sky-400/15 px-1 py-0.5 text-[8.5px] font-bold tracking-wider text-sky-400">HOJE</span>
                                    @endif
                                </div>

                                <div class="flex flex-col gap-1">
                                    @foreach ($cell['previewSlots'] as $preview)
                                        <div class="flex items-center gap-1.5 rounded-[5px] bg-slate-800/80 px-1.5 py-0.5">
                                            <span @class(['size-[5px] rounded-full', $preview['dotClass'] => true])></span>
                                            <span class="font-mono text-[9.5px] font-semibold text-slate-300">{{ $preview['time'] }}</span>
                                        </div>
                                    @endforeach
                                    @if ($cell['extraCount'] > 0)
                                        <div class="pl-0.5 text-[9.5px] text-slate-500">+{{ $cell['extraCount'] }} horários</div>
                                    @endif
                                </div>

                                @if ($cell['slots'] !== [])
                                    <div x-show="hovered" x-cloak
                                        class="absolute left-0 top-[calc(100%+6px)] z-30 w-[230px] rounded-xl border border-slate-700 bg-slate-800 p-3.5 shadow-xl shadow-black/50">
                                        <div class="mb-2 flex items-center justify-between">
                                            <span class="text-[13px] font-bold">{{ $cell['dateLabel'] }}</span>
                                            <span class="font-mono text-[10px] text-slate-400">{{ count($cell['slots']) }} post</span>
                                        </div>
                                        <div class="flex flex-col gap-2">
                                            @foreach ($cell['slots'] as $mslot)
                                                <div class="flex items-start gap-2">
                                                    <span class="w-9 shrink-0 font-mono text-[11px] font-bold text-slate-300">{{ $mslot['time'] }}</span>
                                                    <div class="min-w-0">
                                                        <div class="line-clamp-2 text-[11.5px] leading-tight text-slate-200">{{ $mslot['title'] ?? 'Sem vídeo atribuído' }}</div>
                                                        <div class="mt-0.5 text-[9.5px] font-semibold uppercase text-slate-500">{{ $mslot['status'] }}</div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Modal do picker --}}
    @if ($picker)
        <x-ui.server-modal close="closePicker" :title="'Agendar vídeo — '.$picker['dateLabel']" max-width="max-w-2xl">
            <div class="flex items-center gap-3.5 border-b border-slate-800 px-5 py-3.5">
                <span class="font-mono text-[11px] tracking-[0.08em] text-slate-400">HORÁRIO</span>
                <input type="time" wire:model="picker.time"
                    class="w-28 rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-2 text-center font-mono text-[15px] font-bold text-slate-100 outline-none [color-scheme:dark] focus:border-sky-500" />
            </div>

            <div class="overflow-y-auto px-5 py-4">
                @if (! $picker['browse'])
                    <div class="flex gap-3.5 rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <div class="flex aspect-[9/16] w-20 shrink-0 items-center justify-center rounded-lg bg-slate-800">
                            <x-ui.icon name="play" class="size-6 text-slate-500" />
                        </div>
                        <div class="flex min-w-0 flex-1 flex-col gap-3">
                            <span class="font-mono text-[10.5px] tracking-[0.08em] text-sky-400">SERÁ POSTADO</span>
                            <div>
                                <div class="mb-1 text-[10.5px] font-semibold text-slate-500">Título</div>
                                <input type="text" wire:model="picker.title"
                                    class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-[13.5px] font-semibold text-slate-100 outline-none focus:border-sky-500" />
                            </div>
                            <div>
                                <div class="mb-1 text-[10.5px] font-semibold text-slate-500">Hashtags</div>
                                <input type="text" wire:model="picker.hashtags" placeholder="#shorts #podcast"
                                    class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-[12.5px] text-sky-300 outline-none focus:border-sky-500" />
                            </div>
                            <button type="button" wire:click="pickerBrowse"
                                class="flex cursor-pointer items-center gap-2 self-start rounded-[9px] border border-slate-700 bg-slate-800 px-3.5 py-2 text-[12.5px] font-semibold text-slate-200 transition hover:border-sky-400 hover:text-sky-400">
                                <x-ui.icon name="arrow-path" class="size-3.5" />
                                Selecionar outro vídeo
                            </button>
                        </div>
                    </div>
                @else
                    <input type="text" wire:model.live.debounce.300ms="picker.query" placeholder="Buscar vídeo..."
                        class="mb-3.5 w-full rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-2 text-[13.5px] text-slate-100 outline-none focus:border-sky-500" />
                    <div class="mb-3 font-mono text-[11px] tracking-[0.08em] text-slate-400">ESCOLHA O VÍDEO</div>

                    @if ($pickerVideos === [])
                        <div class="rounded-xl border border-dashed border-slate-700 py-10 text-center text-[13px] text-slate-500">
                            Nenhum vídeo pronto para agendar — marque vídeos como prontos em Meus vídeos.
                        </div>
                    @else
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                            @foreach ($pickerVideos as $video)
                                <button type="button" wire:click="pickerSelect({{ $video['id'] }})" wire:key="picker-video-{{ $video['id'] }}"
                                    @class([
                                        'cursor-pointer overflow-hidden rounded-[11px] border-2 text-left transition',
                                        'border-emerald-500' => $video['selected'],
                                        'border-transparent bg-slate-950/60 hover:border-sky-400' => ! $video['selected'],
                                    ])>
                                    <div class="relative flex aspect-[9/16] max-h-36 w-full items-center justify-center bg-slate-800">
                                        <x-ui.icon name="play" class="size-6 text-slate-500" />
                                        @if ($video['selected'])
                                            <span class="absolute right-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-emerald-500">
                                                <x-ui.icon name="check" class="size-3 text-slate-950" />
                                            </span>
                                        @endif
                                    </div>
                                    <div class="p-2">
                                        <div class="line-clamp-2 text-[11.5px] font-semibold leading-tight">{{ $video['title'] }}</div>
                                        <div class="mt-1 truncate font-mono text-[10px] text-sky-400/80">{{ $video['tags'] }}</div>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="closePicker">Cancelar</x-ui.button>
                <x-ui.button wire:click="savePicker">Salvar horário</x-ui.button>
            </x-slot:footer>
        </x-ui.server-modal>
    @endif
</section>
