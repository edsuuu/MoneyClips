<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Auto-postagem"
        title="Agenda"
        :subtitle="'Semana de '.$weekStart->format('d/m').' a '.$weekEnd->format('d/m').' — horários configuráveis por dia da semana.'"
    />

    {{-- Toggles de plataforma + próximo disparo --}}
    <div class="grid gap-4 md:grid-cols-3">
        {{-- YouTube toggle --}}
        <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-800 bg-slate-950/70 p-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">YouTube</p>
                <p class="mt-1 text-sm">
                    @if ($user?->auto_post_youtube_enabled)
                        <span class="inline-flex items-center gap-1.5 text-emerald-400">
                            <span class="size-2 rounded-full bg-emerald-400"></span> Ativo
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-slate-500">
                            <span class="size-2 rounded-full bg-slate-600"></span> Pausado
                        </span>
                    @endif
                </p>
            </div>
            <button
                type="button"
                wire:click="toggleYoutube"
                wire:loading.attr="disabled"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors disabled:opacity-60 {{ $user?->auto_post_youtube_enabled ? 'bg-emerald-500' : 'bg-slate-700' }}"
                aria-pressed="{{ $user?->auto_post_youtube_enabled ? 'true' : 'false' }}"
            >
                <span class="sr-only">Toggle YouTube</span>
                <span class="inline-block size-4 transform rounded-full bg-white shadow transition {{ $user?->auto_post_youtube_enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
            </button>
        </div>

        {{-- TikTok toggle --}}
        <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-800 bg-slate-950/70 p-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">TikTok</p>
                <p class="mt-1 text-sm">
                    @if ($user?->auto_post_tiktok_enabled)
                        <span class="inline-flex items-center gap-1.5 text-emerald-400">
                            <span class="size-2 rounded-full bg-emerald-400"></span> Ativo
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-slate-500">
                            <span class="size-2 rounded-full bg-slate-600"></span> Pausado
                        </span>
                    @endif
                </p>
            </div>
            <button
                type="button"
                wire:click="toggleTiktok"
                wire:loading.attr="disabled"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors disabled:opacity-60 {{ $user?->auto_post_tiktok_enabled ? 'bg-emerald-500' : 'bg-slate-700' }}"
                aria-pressed="{{ $user?->auto_post_tiktok_enabled ? 'true' : 'false' }}"
            >
                <span class="sr-only">Toggle TikTok</span>
                <span class="inline-block size-4 transform rounded-full bg-white shadow transition {{ $user?->auto_post_tiktok_enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
            </button>
        </div>

        {{-- Próximo disparo --}}
        <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Próximo disparo</p>
            @if ($nextSlot)
                <p class="mt-2 text-sm">
                    <span class="font-medium text-slate-100">{{ $nextSlot['day_label'] }} {{ $nextSlot['time_label'] }}</span>
                    <span class="ml-2 text-slate-400">{{ $nextSlot['in'] }}</span>
                </p>
            @else
                <p class="mt-2 text-sm text-slate-500">Nenhum slot futuro nesta semana.</p>
            @endif
        </div>
    </div>

    {{-- Horários por dia da semana (fonte: banco — muda sem deploy) --}}
    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Horários por dia da semana</p>
                <p class="mt-1 text-xs text-slate-500">Cada dia tem seus próprios horários. Dia sem horário = sem postagens. Vale no próximo tick do scheduler.</p>
            </div>
            <x-ui.button variant="primary" wire:click="saveSchedule" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="saveSchedule">Salvar agenda</span>
                <span wire:loading wire:target="saveSchedule">Salvando…</span>
            </x-ui.button>
        </div>

        <div class="mt-4 grid gap-2.5">
            @foreach (\App\Livewire\Schedule\Index::WEEKDAY_LABELS as $dow => $dowLabel)
                <div class="flex flex-wrap items-center gap-2" wire:key="day-{{ $dow }}">
                    <span class="w-10 shrink-0 text-sm font-medium text-slate-300">{{ $dowLabel }}</span>
                    @foreach ($scheduleTimes[$dow] ?? [] as $i => $time)
                        <span
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-700 bg-slate-900 pl-2"
                            wire:key="slot-{{ $dow }}-{{ $i }}"
                        >
                            <input
                                type="time"
                                wire:model="scheduleTimes.{{ $dow }}.{{ $i }}"
                                class="bg-transparent py-1.5 text-sm tabular-nums text-slate-100 [color-scheme:dark] focus:outline-none"
                            />
                            <button
                                type="button"
                                wire:click="removeTime({{ $dow }}, {{ $i }})"
                                class="cursor-pointer px-1.5 py-1.5 text-slate-500 transition hover:text-red-400"
                                title="Remover horário"
                            >
                                <x-ui.icon name="x-mark" class="size-3.5" />
                            </button>
                        </span>
                    @endforeach
                    <x-ui.button size="xs" icon="plus" wire:click="addTime({{ $dow }})">horário</x-ui.button>
                    @if (($scheduleTimes[$dow] ?? []) === [])
                        <span class="text-xs text-slate-600">sem postagens</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Grade semanal --}}
    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950/70">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                    <th class="px-4 py-3 w-20">Dia</th>
                    @for ($i = 1; $i <= $maxSlots; $i++)
                        <th class="px-4 py-3">Slot {{ $i }}</th>
                    @endfor
                    <th class="px-4 py-3 w-16 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day)
                    @php
                        $posted = count(array_filter($day['slots'], fn ($s) => $s['status'] === 'posted'));
                        $total = count($day['slots']);
                    @endphp
                    <tr class="border-b border-slate-800/70 last:border-0 {{ $day['isToday'] ? 'bg-slate-900/60' : '' }}">
                        <td class="px-4 py-3">
                            <div class="flex flex-col">
                                <span class="font-medium text-slate-100">{{ $day['label'] }}</span>
                                <span class="text-xs text-slate-500">{{ $day['date']->format('d/m') }}</span>
                                @if ($day['isToday'])
                                    <span class="mt-1 inline-flex w-fit items-center rounded bg-emerald-500/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-400">hoje</span>
                                @endif
                            </div>
                        </td>
                        @foreach ($day['slots'] as $slot)
                            <td class="px-4 py-3 align-top">
                                @php
                                    $cellClasses = match ($slot['status']) {
                                        'posted' => 'border-emerald-500/30 bg-emerald-500/5',
                                        'future' => $slot['is_next'] ? 'border-sky-500/50 bg-sky-500/10' : 'border-slate-800 bg-slate-950/40',
                                        'skipped' => 'border-amber-500/30 bg-amber-500/5',
                                        default => 'border-slate-800 bg-slate-950/40',
                                    };
                                @endphp
                                <div class="rounded-lg border {{ $cellClasses }} p-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-medium tabular-nums text-slate-100">{{ $slot['time_label'] }}</span>
                                        @if ($slot['status'] === 'posted')
                                            <x-ui.icon name="check-circle" class="size-4 text-emerald-400" />
                                        @elseif ($slot['is_next'])
                                            <x-ui.icon name="bell" class="size-4 text-sky-400" />
                                        @elseif ($slot['status'] === 'skipped')
                                            <x-ui.icon name="x-mark" class="size-4 text-amber-400" />
                                        @endif
                                    </div>
                                    @if ($slot['short'])
                                        <p class="mt-1 line-clamp-2 text-[11px] text-slate-400" title="{{ $slot['short']->title ?? $slot['short']->youtube_id }}">
                                            {{ $slot['short']->title ?? $slot['short']->youtube_id }}
                                        </p>
                                        <div class="mt-1.5 flex gap-1">
                                            @if ($slot['short']->posted_youtube_at)
                                                <span class="inline-flex items-center rounded bg-red-500/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wider text-red-400" title="Postado no YouTube">YT</span>
                                            @endif
                                            @if ($slot['short']->posted_tiktok_at)
                                                <span class="inline-flex items-center rounded bg-pink-500/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wider text-pink-400" title="Postado no TikTok">TT</span>
                                            @endif
                                        </div>
                                    @elseif ($slot['status'] === 'skipped' && $day['isToday'])
                                        <button
                                            type="button"
                                            wire:click="forceDispatch('{{ $day['date']->format('Y-m-d') }}')"
                                            wire:loading.attr="disabled"
                                            wire:confirm="Forçar disparo agora? Vai sortear o próximo Short do estoque e postar nas plataformas ativas."
                                            class="mt-1.5 w-full cursor-pointer rounded bg-amber-500/15 px-2 py-1 text-[10px] font-semibold text-amber-300 transition hover:bg-amber-500/25 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="forceDispatch">Forçar agora</span>
                                            <span wire:loading wire:target="forceDispatch">Enviando…</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        @endforeach
                        {{-- Dias com menos slots que o máximo: completa com células vazias --}}
                        @for ($i = count($day['slots']); $i < $maxSlots; $i++)
                            <td class="px-4 py-3"></td>
                        @endfor
                        <td class="px-4 py-3 text-right text-xs tabular-nums text-slate-400">{{ $posted }}/{{ $total }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Legenda --}}
    <div class="flex flex-wrap items-center gap-4 text-xs text-slate-500">
        <span class="inline-flex items-center gap-1.5">
            <span class="size-3 rounded border border-emerald-500/30 bg-emerald-500/5"></span> Postado
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="size-3 rounded border border-sky-500/50 bg-sky-500/10"></span> Próximo disparo
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="size-3 rounded border border-slate-800 bg-slate-950/40"></span> Futuro
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="size-3 rounded border border-amber-500/30 bg-amber-500/5"></span> Pulado (cron offline ou erro)
        </span>
    </div>
</section>
