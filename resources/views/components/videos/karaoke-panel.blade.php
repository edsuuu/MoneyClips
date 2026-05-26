<div
    class="relative overflow-y-auto rounded-xl border border-slate-800 bg-slate-900/80 p-4"
    style="max-height: 420px;"
    x-ref="karaokePanel"
    x-init="$watch('currentWordIdx', () => {
        $nextTick(() => {
            const active = $refs.karaokePanel.querySelector('.karaoke-active');
            if (active) {
                const target = active.offsetTop - ($refs.karaokePanel.clientHeight / 2) + (active.clientHeight / 2);
                $refs.karaokePanel.scrollTo({ top: target, behavior: 'smooth' });
            }
        });
    })"
>
    <div class="mb-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">Transcrição ao vivo</div>
    <template x-if="karaokeWords.length > 0">
        <div class="flex flex-wrap gap-x-1 gap-y-1 leading-7 text-sm">
            <template x-for="(item, idx) in karaokeWords" :key="idx">
                <span
                    :class="{
                        'karaoke-active rounded bg-blue-500/20 px-1 text-blue-200 font-semibold': idx === currentWordIdx,
                        'text-slate-200': idx < currentWordIdx,
                        'text-slate-500': idx > currentWordIdx,
                    }"
                    x-text="item.text"
                ></span>
            </template>
        </div>
    </template>
    <template x-if="karaokeWords.length === 0">
        <p class="text-xs text-slate-500">Nenhuma transcrição disponível.</p>
    </template>
</div>
