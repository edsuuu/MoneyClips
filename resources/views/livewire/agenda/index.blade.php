<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <x-studio.page-header
        eyebrow="Auto-postagem"
        title="Agenda"
        :subtitle="'Semana de '.$weekStart->format('d/m').' a '.$weekEnd->format('d/m').' — 5 slots/dia (gap ~3h), minuto sorteado estável por dia.'"
    >
        <x-slot:actions>
            <x-ui.button :href="route('settings.auto-post')" variant="ghost" icon="cog-6-tooth" wire:navigate>
                Plataformas
            </x-ui.button>
        </x-slot:actions>
    </x-studio.page-header>

    {{-- Status + próximo --}}
    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">YouTube</p>
            <p class="mt-2 text-sm">
                @if ($settings->youtube_enabled)
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
        <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">TikTok</p>
            <p class="mt-2 text-sm">
                @if ($settings->tiktok_enabled)
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

    {{-- Grade semanal --}}
    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950/70">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                    <th class="px-4 py-3 w-20">Dia</th>
                    @foreach ($slotHours as $hour)
                        <th class="px-4 py-3">{{ sprintf('%02dh', $hour) }}</th>
                    @endforeach
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
                                    @endif
                                </div>
                            </td>
                        @endforeach
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
