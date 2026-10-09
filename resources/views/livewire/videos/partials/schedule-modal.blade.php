<x-ui.server-modal close="closeSchedule" title="Agendar postagem">
    <div class="flex flex-col gap-5 overflow-y-auto p-5">
        <div class="flex items-center gap-3">
            <div class="flex h-14 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-800">
                <x-ui.icon name="play" class="size-4 text-slate-500" />
            </div>
            <div class="line-clamp-2 min-w-0 text-sm font-bold">{{ $scheduleModal['title'] }}</div>
        </div>

        @if ($scheduleModal['accounts'] === [])
            <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-800 px-4 py-6 text-center">
                <span class="text-[13px] text-slate-400">Nenhuma conta pronta para postar.</span>
                <x-ui.button size="sm" :href="route('accounts.index')" wire:navigate>Ir para Contas</x-ui.button>
            </div>
        @else
            <fieldset class="flex flex-col gap-2.5">
                <legend class="mb-2.5 font-mono text-[10.5px] tracking-[0.08em] text-slate-500">ONDE POSTAR</legend>
                @foreach ($scheduleModal['accounts'] as $account)
                    <label wire:key="schedule-account-{{ $account['id'] }}" @class([
                        'flex items-start gap-2.5 rounded-[10px] border border-slate-800 px-3 py-2.5 text-sm',
                        'cursor-pointer hover:border-slate-700' => ! $account['is_blocked'],
                        'cursor-not-allowed opacity-60' => $account['is_blocked'],
                    ])>
                        <input type="checkbox" wire:model="scheduleAccountIds" value="{{ $account['id'] }}" @disabled($account['is_blocked'])
                            class="mt-0.5 size-4 shrink-0 cursor-pointer rounded border-slate-600 bg-slate-900 accent-sky-500 disabled:cursor-not-allowed" />
                        <span class="flex min-w-0 flex-col">
                            <span class="truncate font-semibold text-slate-100">{{ $account['label'] }}</span>
                            <span class="text-xs text-slate-500">{{ $account['blocked_reason'] ?? $account['provider_label'] }}</span>
                        </span>
                    </label>
                @endforeach
                @error('scheduleAccountIds') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </fieldset>

            @if ($scheduleModal['can_schedule'])
                <fieldset class="flex flex-col gap-2.5">
                    <legend class="mb-2.5 font-mono text-[10.5px] tracking-[0.08em] text-slate-500">QUANDO</legend>
                    <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                        <input type="radio" wire:model.live="scheduleWhen" value="next" class="mt-1 size-4 shrink-0 cursor-pointer accent-sky-500" />
                        <span class="flex min-w-0 flex-col">
                            <span class="font-semibold text-slate-100">No próximo horário bom</span>
                            <span class="text-xs text-slate-500">{{ $scheduleModal['next_label'] }}</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                        <input type="radio" wire:model.live="scheduleWhen" value="custom" class="mt-1 size-4 shrink-0 cursor-pointer accent-sky-500" />
                        <span class="font-semibold text-slate-100">Escolher data e hora</span>
                    </label>
                    @if ($scheduleWhen === 'custom')
                        <div class="pl-6.5">
                            <x-ui.input type="datetime-local" wire:model="scheduleAt" :min="$scheduleModal['min_at']" aria-label="Data e hora da postagem" />
                        </div>
                    @endif
                    <p class="text-xs leading-relaxed text-slate-500">{{ $scheduleModal['times_help'] }}</p>
                </fieldset>
            @endif
        @endif
    </div>

    <x-slot:footer>
        <x-ui.button variant="ghost" wire:click="closeSchedule">Cancelar</x-ui.button>
        @if ($scheduleModal['can_schedule'])
            <x-ui.button variant="primary" icon="calendar-days" wire:click="saveSchedule" wire:loading.attr="disabled" wire:target="saveSchedule">
                <span wire:loading.remove wire:target="saveSchedule">Agendar</span>
                <span wire:loading wire:target="saveSchedule">Agendando…</span>
            </x-ui.button>
        @endif
    </x-slot:footer>
</x-ui.server-modal>
