@props([
    'title' => 'Transcrição por tempo',
    'subtitle' => 'Ajuste texto e tempos; isso alimenta a legenda com sincronia.',
    'saveLabel' => 'Salvar sincronia',
    'saveAction' => '$wire.saveTimedWords(timedWords)',
])

<x-studio.panel :title="$title" :subtitle="$subtitle">
    <template x-if="timedWords.length > 0">
        <div>
            <div class="mb-2 grid grid-cols-[auto_1fr_88px_88px] gap-3 px-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">
                <div class="w-6 text-center">#</div>
                <div>Texto</div>
                <div>Start</div>
                <div>End</div>
            </div>

            <div class="max-h-[360px] space-y-2 overflow-y-auto rounded-xl border border-slate-800 bg-slate-950/80 p-2 pr-2">
                <template x-for="(word, idx) in timedWords" :key="idx">
                    <div class="grid grid-cols-[auto_1fr_88px_88px] gap-3 items-center">
                        <span class="w-6 text-center text-xs tabular-nums text-slate-500" x-text="idx + 1"></span>
                        <input type="text" x-model="word.text" class="w-full rounded-lg border border-slate-800 bg-slate-900 p-1.5 text-sm text-slate-100 outline-none transition focus:border-blue-400" />
                        <input type="number" step="0.01" x-model.number="word.start" class="w-full rounded-lg border border-slate-800 bg-slate-900 p-1.5 text-sm tabular-nums text-slate-100 outline-none transition focus:border-blue-400" />
                        <input type="number" step="0.01" x-model.number="word.end" class="w-full rounded-lg border border-slate-800 bg-slate-900 p-1.5 text-sm tabular-nums text-slate-100 outline-none transition focus:border-blue-400" />
                    </div>
                </template>
            </div>

            <div class="mt-4 flex justify-end">
                <flux:button size="sm" variant="filled" x-on:click="{{ $saveAction }}">
                    {{ $saveLabel }}
                </flux:button>
            </div>
        </div>
    </template>

    <template x-if="timedWords.length === 0">
        <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-4 text-center text-sm text-slate-500">
            A edição por tempo não está disponível para este vídeo ainda.
        </div>
    </template>
</x-studio.panel>
