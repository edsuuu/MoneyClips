<section class="mx-auto flex w-full max-w-4xl flex-col gap-6" @if ($hasPosting) wire:poll.10s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1 basis-72" data-tour="agenda-summary">
            <div class="mb-2 font-mono text-[11px] tracking-[0.1em] text-slate-500">AGENDA</div>
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-50">Agenda</h1>
            <p class="mt-1.5 text-sm text-slate-400">{{ $subtitle }}</p>
            @if ($hasAccounts)
                <div class="mt-3 flex flex-wrap gap-2">
                    <span class="rounded-full border border-slate-800 bg-slate-900 px-3 py-1 text-xs font-semibold text-slate-300">{{ $summaryLabel }}</span>
                    @if ($nextLabel)
                        <span class="rounded-full border border-slate-800 bg-slate-900 px-3 py-1 text-xs font-semibold text-slate-300">{{ $nextLabel }}</span>
                    @endif
                </div>
            @endif
            @if ($shortAgendaWarning)
                <p class="mt-2 text-[13px] text-amber-700 dark:text-amber-300">{{ $shortAgendaWarning }}</p>
            @endif
        </div>
        <x-ui.button :href="route('videos.index')" icon="film" wire:navigate data-tour="agenda-ready">Ver Shorts prontos</x-ui.button>
    </div>

    @if (! $hasAccounts)
        <x-ui.callout variant="info" icon="user-circle" heading="Conecte uma conta para postar">
            <p>Os Shorts saem no TikTok e no YouTube. Conecte pelo menos uma conta.</p>
            <x-ui.button size="sm" class="mt-3" :href="route('accounts.index')" wire:navigate>Conectar conta</x-ui.button>
        </x-ui.callout>
    @else
        @if ($attention !== [])
            <div class="flex flex-col gap-3 rounded-xl border border-red-500/30 bg-red-500/5 p-4" data-tour="agenda-attention">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm font-bold text-red-800 dark:text-red-200">
                        <x-ui.icon name="exclamation-triangle" class="size-4" />
                        {{ $attentionTitle }}
                    </div>
                    @if ($missedLabel)
                        <x-ui.button size="xs" variant="outline" icon="arrow-path" class="max-sm:w-full" wire:click="retryAllMissed" wire:loading.attr="disabled" wire:target="retryAllMissed">
                            <span wire:loading.remove wire:target="retryAllMissed">{{ $missedLabel }}</span>
                            <span wire:loading wire:target="retryAllMissed">Reagendando…</span>
                        </x-ui.button>
                    @endif
                </div>
                <div class="flex flex-col divide-y divide-red-500/15">
                    @foreach ($attention as $post)
                        <div class="flex flex-col gap-1.5 py-2.5 first:pt-0 last:pb-0" wire:key="attention-{{ $post['id'] }}">
                            <span class="truncate text-sm font-semibold text-slate-100">{{ $post['short_title'] }}</span>
                            @include('livewire.videos.partials.post-status', ['post' => $post])
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($emptyText)
            <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-800 px-4 py-10 text-center" data-tour="agenda-days">
                <span class="text-[15px] font-bold">Nenhum Short agendado</span>
                <span class="max-w-md text-[13px] text-slate-500">{{ $emptyText }}</span>
                <div class="flex flex-wrap justify-center gap-2">
                    <x-ui.button size="sm" :href="route('videos.index')" wire:navigate>Ir para Meus vídeos</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" :href="route('accounts.index')" wire:navigate>Abrir Contas</x-ui.button>
                </div>
            </div>
        @else
            <div class="flex flex-col gap-5" data-tour="agenda-days">
                @foreach ($days as $day)
                    <div wire:key="day-{{ $day['key'] }}">
                        <div class="mb-2 flex items-baseline justify-between gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.1em] text-slate-500">
                            <span>{{ $day['label'] }}</span>
                            <span>{{ $day['count_label'] }}</span>
                        </div>
                        @if ($day['rows'] === [])
                            <div class="px-1 text-[13px] text-slate-500">Nada agendado.</div>
                        @else
                            <div class="flex flex-col divide-y divide-slate-800 rounded-[14px] border border-slate-800 bg-slate-900">
                                @foreach ($day['rows'] as $row)
                                    <div class="flex gap-3 p-3.5" wire:key="row-{{ $day['key'] }}-{{ $row['key'] }}">
                                        <span class="w-11 shrink-0 pt-0.5 font-mono text-sm font-bold text-slate-200">{{ $row['time'] }}</span>
                                        <div class="flex h-12 w-9 shrink-0 items-center justify-center rounded-md bg-slate-800 max-sm:hidden">
                                            <x-ui.icon name="play" class="size-3.5 text-slate-500" />
                                        </div>
                                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                                            <div class="flex items-start justify-between gap-2">
                                                <span class="line-clamp-2 min-w-0 text-sm font-bold leading-snug">{{ $row['title'] }}</span>
                                                @if ($row['can_schedule'])
                                                    <x-ui.button size="xs" variant="ghost" icon="calendar-days" class="shrink-0" wire:click="openSchedule({{ $row['short_id'] }})">Agendar</x-ui.button>
                                                @endif
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                @foreach ($row['posts'] as $post)
                                                    @include('livewire.videos.partials.post-status', ['post' => $post])
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    @if ($scheduleModal)
        @include('livewire.videos.partials.schedule-modal')
    @endif
</section>
