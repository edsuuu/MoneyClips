<section
    class="mx-auto w-full max-w-7xl"
    @if(! $ready) wire:poll.4s="refreshStatus" @endif
    x-data="{
        player: null,
        duration: @js((float) ($video->duration_seconds ?? 0)),
        current: 0,
        transcriptText: @js($text),
        timedWords: @js($timedWords ?? []),
        registerPlayer(el) {
            this.player = el;
            el.volume = 0.2;
            const onMeta = () => {
                if (Number.isFinite(el.duration) && el.duration > 0) this.duration = el.duration;
                this.current = el.currentTime || 0;
            };
            const onTime = () => { this.current = el.currentTime || 0; };
            el.addEventListener('loadedmetadata', onMeta);
            el.addEventListener('timeupdate', onTime);
            onMeta();
        },
        get transcriptData() {
            if (!this.transcriptText) return { words: [], totalChars: 0 };
            const rawWords = this.transcriptText.split(/\s+/).filter((w) => w.length > 0);
            let totalChars = 0;
            const words = rawWords.map((w) => {
                const startChar = totalChars;
                totalChars += w.length;
                return { text: w, startChar, endChar: totalChars };
            });
            return { words, totalChars };
        },
        get karaokeWords() {
            if (this.timedWords && this.timedWords.length > 0) return this.timedWords;
            return this.transcriptData.words;
        },
        get currentWordIdx() {
            const words = this.karaokeWords;
            if (!this.duration || words.length === 0) return -1;

            if (this.timedWords && this.timedWords.length > 0) {
                const t = this.current;
                for (let i = 0; i < words.length; i++) {
                    if (t >= words[i].start && t <= words[i].end) return i;
                }
                for (let i = 0; i < words.length; i++) {
                    if (t < words[i].start) return Math.max(0, i - 1);
                }
                return words.length - 1;
            }

            const targetChar = (this.current / this.duration) * this.transcriptData.totalChars;
            for (let i = 0; i < words.length; i++) {
                if (targetChar >= words[i].startChar && targetChar <= words[i].endChar) return i;
            }
            return words.length - 1;
        },
    }"
>
    <div class="space-y-6">
        <x-studio.page-header
            eyebrow="Revisão"
            :title="$video->title ?? 'Processando vídeo...'"
            subtitle="Revise o vídeo, ajuste o texto completo e confirme a sincronia por tempo antes de liberar o editor."
        >
            <x-slot:meta>
                <flux:badge>{{ $video->status?->label ?? '—' }}</flux:badge>
                @if($video->duration_seconds)
                    <span class="text-xs tabular-nums text-slate-500">{{ gmdate('i:s', (int) $video->duration_seconds) }} min</span>
                @endif
            </x-slot:meta>
        </x-studio.page-header>

        @if(! $ready)
            <x-studio.panel title="Processamento em andamento" subtitle="A tela atualiza sozinha quando a transcrição estiver pronta para revisão.">
                <div class="flex flex-col items-center justify-center gap-5 py-12 text-center">
                    <flux:heading size="lg">Baixando e transcrevendo o vídeo...</flux:heading>
                    @if($jobId)
                        <div class="w-full max-w-xl">
                            @include('livewire.videos._progress', ['jobId' => $jobId, 'wsUrl' => $wsUrl])
                        </div>
                    @else
                        <flux:icon.loading class="size-8 text-slate-500" />
                    @endif
                    <flux:text class="text-slate-500">Esta página atualiza sozinha quando a transcrição ficar pronta.</flux:text>
                </div>
            </x-studio.panel>
        @else
            <div class="space-y-6">
                <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <x-studio.panel title="Player" subtitle="Escute o material e valide o texto em paralelo.">
                    @if($playerUrl)
                        <video
                            x-init="window.initAdaptiveVideoPlayer($el); registerPlayer($el)"
                            @if($hlsUrl) data-hls-src="{{ $hlsUrl }}" @endif
                            @if($playerUrl) data-fallback-src="{{ $playerUrl }}" @endif
                            @if($playerUrl && ! $hlsUrl) src="{{ $playerUrl }}" @endif
                            controls
                            class="w-full rounded-xl bg-black object-contain"
                            style="max-height: 440px;"
                        ></video>
                    @else
                        <div class="flex aspect-video w-full items-center justify-center rounded-xl border border-dashed border-slate-700 text-slate-500">
                            Vídeo ainda não disponível para reprodução.
                        </div>
                    @endif
                    </x-studio.panel>

                    <x-videos.karaoke-panel />
                </div>

                <x-studio.panel
                    title="Texto completo da transcrição"
                    subtitle="Use esse campo para revisão textual geral antes de mexer na camada temporal."
                >
                    <flux:textarea
                        class="mt-3"
                        x-model="transcriptText"
                        rows="12"
                        placeholder="A transcrição aparecerá aqui..."
                    />
                    <div class="mt-3 flex justify-end">
                        <flux:button size="sm" variant="subtle" class="cursor-pointer" x-on:click="$wire.saveTranscript(transcriptText)">
                            Salvar texto completo
                        </flux:button>
                    </div>
                </x-studio.panel>

                <x-videos.timed-words-editor
                    title="Transcrição por tempo"
                    subtitle="Essa estrutura é priorizada para gerar legenda sincronizada corretamente."
                    save-label="Salvar sincronia por tempo"
                    :save-action="'$wire.saveTimedWords(timedWords)'"
                />

                <div class="flex gap-3">
                    <flux:button wire:click="confirm" variant="primary" icon="check" class="cursor-pointer">
                        <span wire:loading.remove wire:target="confirm">Confirmar e ir para os cortes</span>
                        <span wire:loading wire:target="confirm">Enviando...</span>
                    </flux:button>
                    <flux:button :href="route('videos.create')" variant="ghost" class="cursor-pointer" wire:navigate>Voltar</flux:button>
                </div>
            </div>
        @endif
    </div>
</section>
