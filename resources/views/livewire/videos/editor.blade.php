<section
    class="mx-auto w-full max-w-7xl"
    @if($activeJobId) wire:poll.5s="refreshStatus" @endif
    x-data="{
        player: null,
        duration: @js((float) ($video->duration_seconds ?? 0)),
        current: 0,
        start: @js((float) $newStart),
        end: @js((float) $newEnd),
        zoom: 1.0,
        dragging: null,
        viewportWidth: 900,
        resizeHandler: null,
        thumbTrack: [],
        thumbFrameCount: 14,
        loadingThumbs: false,
        transcriptText: @js($transcript?->activeText() ?? ''),
        timedWords: @js($timedWords ?? []),
        fitSelectionToViewport() {
            if (!this.$refs.timelineViewport) return;
            const vpWidth = this.$refs.timelineViewport.clientWidth || this.viewportWidth;
            const rangeSecs = Math.max(0.5, this.end - this.start);
            const basePx = 42;
            const idealZoom = vpWidth / (rangeSecs * basePx);
            this.zoom = this.round(this.clamp(idealZoom, 0.1, 5));
            this.$nextTick(() => {
                if (this.$refs.timelineViewport) {
                    this.$refs.timelineViewport.scrollLeft = this.timeToPx(this.start);
                }
            });
        },
        init() {
            this.end = this.end > this.start ? this.end : Math.max(this.start + 1, 60);
            this.resizeHandler = () => {
                if (this.$refs.timelineViewport) {
                    this.viewportWidth = this.$refs.timelineViewport.clientWidth;
                    const nextFrameCount = Math.min(22, Math.max(10, Math.ceil(this.viewportWidth / 82)));
                    if (nextFrameCount !== this.thumbFrameCount) {
                        this.thumbFrameCount = nextFrameCount;
                        this.buildThumbTrack();
                    }
                }
            };
            this.$nextTick(() => {
                this.resizeHandler();
                this.fitSelectionToViewport();
            });
            window.addEventListener('resize', this.resizeHandler);
            this.syncWire();
        },
        destroy() {
            if (this.resizeHandler) {
                window.removeEventListener('resize', this.resizeHandler);
            }
        },
        registerPlayer(el) {
            this.player = el;
            el.volume = 0.2;
            const onMeta = () => {
                if (Number.isFinite(el.duration) && el.duration > 0) {
                    this.duration = el.duration;
                    if (this.end > this.duration) this.end = this.duration;
                    if (!this.thumbTrack.length) {
                        this.buildThumbTrack();
                    }
                    this.$nextTick(() => this.fitSelectionToViewport());
                }
                this.current = el.currentTime || 0;
            };
            const onTime = () => {
                this.current = el.currentTime || 0;
            };
            el.addEventListener('loadedmetadata', onMeta);
            el.addEventListener('timeupdate', onTime);
            onMeta();
        },
        clamp(value, min, max) {
            return Math.min(max, Math.max(min, value));
        },
        round(value) {
            return Math.round(value * 1000) / 1000;
        },
        adjustZoom(event) {
            const direction = event.deltaY < 0 ? 0.2 : -0.2;
            this.zoom = this.round(this.clamp(this.zoom + direction, 0.1, 5));
        },
        nudgePlayhead(delta) {
            this.jumpTo(this.current + delta);
        },
        get pxPerSecond() {
            return 42 * this.zoom;
        },
        get timelineWidth() {
            return Math.max(this.viewportWidth, Math.ceil((this.duration || 1) * this.pxPerSecond));
        },
        get transcriptData() {
            if (!this.transcriptText) return { words: [], totalChars: 0 };
            const rawWords = this.transcriptText.split(/\s+/).filter(w => w.length > 0);
            let totalChars = 0;
            const words = rawWords.map(w => {
                const startChar = totalChars;
                totalChars += w.length;
                return { text: w, startChar, endChar: totalChars };
            });
            return { words, totalChars };
        },
        get karaokeWords() {
            if (this.timedWords && this.timedWords.length > 0) {
                return this.timedWords;
            }
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

            // Fallback to char-based approach
            const targetChar = (this.current / this.duration) * this.transcriptData.totalChars;
            for (let i = 0; i < words.length; i++) {
                if (targetChar >= words[i].startChar && targetChar <= words[i].endChar) {
                    return i;
                }
            }
            return words.length - 1;
        },
        timeToPx(time) {
            return this.clamp(time, 0, this.duration || time) * this.pxPerSecond;
        },
        pxToTime(px) {
            return this.clamp(px / this.pxPerSecond, 0, this.duration || 0);
        },
        formatClock(seconds) {
            const safe = Math.max(0, Number(seconds || 0));
            const minutes = Math.floor(safe / 60);
            const secs = Math.floor(safe % 60);
            const millis = Math.floor((safe - Math.floor(safe)) * 1000);
            return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}.${String(millis).padStart(3, '0')}`;
        },
        formatRuler(seconds) {
            const safe = Math.max(0, Number(seconds || 0));
            const minutes = Math.floor(safe / 60);
            const secs = Math.floor(safe % 60);
            return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        },
        timelineStep() {
            if (this.zoom >= 4) return 0.1;
            if (this.zoom >= 3) return 0.25;
            if (this.zoom >= 2) return 0.5;
            if (this.zoom >= 1.2) return 1;
            if (this.zoom >= 0.8) return 2;
            if (this.zoom >= 0.3) return 5;
            if (this.zoom >= 0.15) return 10;
            return 30;
        },
        rulerMarks() {
            const step = this.timelineStep();
            const total = Math.max(1, Math.ceil((this.duration || 0) / step));
            const majorEvery = step <= 0.1 ? 5 : step <= 0.25 ? 4 : step <= 0.5 ? 4 : step <= 1 ? 5 : step <= 2 ? 3 : step <= 5 ? 2 : 3;
            return Array.from({ length: total + 1 }, (_, idx) => {
                const time = Math.min((this.duration || 0), idx * step);
                return {
                    time,
                    label: idx % majorEvery === 0,
                    height: idx % majorEvery === 0 ? 28 : 16,
                };
            });
        },
        jumpTo(time) {
            const next = this.round(this.clamp(time, 0, this.duration || 0));
            this.current = next;
            if (this.player) {
                this.player.currentTime = next;
            }
        },
        buildThumbTrack() {
            const total = this.duration || this.player?.duration || 0;
            if (!total) {
                this.thumbTrack = [];
                return;
            }

            const frameCount = Math.min(22, Math.max(10, this.thumbFrameCount));
            this.thumbTrack = Array.from({ length: frameCount }, (_, index) => {
                const ratio = frameCount === 1 ? 0 : index / (frameCount - 1);
                const time = this.round(Math.min(total, ratio * total));
                return {
                    id: `thumb-${index}-${Math.round(time * 1000)}`,
                    left: `${(index / frameCount) * 100}%`,
                    width: `${100 / frameCount}%`,
                    time,
                };
            });
        },
        primeThumb(video, time) {
            const seekToTime = () => {
                try {
                    video.currentTime = Math.max(0, Number(time || 0));
                } catch (error) {
                    console.warn('Nao foi possivel posicionar thumbnail.', error);
                }
            };

            if (video.readyState >= 1) {
                seekToTime();
                return;
            }

            video.addEventListener('loadedmetadata', seekToTime, { once: true });
            video.addEventListener('seeked', () => video.pause(), { once: true });
        },
        setStartFromCurrent() {
            this.start = this.round(this.clamp(this.current, 0, this.end - 0.05));
            this.syncWire();
        },
        setEndFromCurrent() {
            const maxDuration = this.duration || Math.max(this.current, this.end, this.start + 0.05);
            this.end = this.round(this.clamp(this.current, this.start + 0.05, maxDuration));
            this.syncWire();
        },
        syncWire() {
            this.start = this.round(this.start);
            this.end = this.round(this.end);
            this.$wire.set('newStart', this.start, false);
            this.$wire.set('newEnd', this.end, false);
        },
        addCutFromTimeline() {
            this.syncWire();
            this.$wire.addCut();
        },
        seekFromPointer(event) {
            if (!this.$refs.timelineInner || !this.$refs.timelineViewport) return;
            const rect = this.$refs.timelineInner.getBoundingClientRect();
            const px = event.clientX - rect.left + this.$refs.timelineViewport.scrollLeft;
            this.jumpTo(this.pxToTime(px));
        },
        beginDrag(kind, event) {
            event.preventDefault();
            this.dragging = kind;
            const move = (ev) => this.handleDrag(ev);
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                this.dragging = null;
                this.syncWire();
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        handleDrag(event) {
            if (!this.dragging || !this.$refs.timelineInner || !this.$refs.timelineViewport) return;
            const rect = this.$refs.timelineInner.getBoundingClientRect();
            const px = event.clientX - rect.left + this.$refs.timelineViewport.scrollLeft;
            const time = this.round(this.pxToTime(px));
            const maxDuration = this.duration || Math.max(this.end, time);

            if (this.dragging === 'start') {
                this.start = this.clamp(time, 0, this.end - 0.05);
                this.jumpTo(this.start);
                return;
            }
            if (this.dragging === 'end') {
                this.end = this.clamp(time, this.start + 0.05, maxDuration);
                this.jumpTo(this.end);
                return;
            }
            if (this.dragging === 'playhead') {
                this.jumpTo(time);
            }
        },
        syncStartInput() {
            this.start = this.round(this.clamp(this.start, 0, this.end - 0.05));
            this.syncWire();
        },
        syncEndInput() {
            const maxDuration = this.duration || Math.max(this.end, this.start + 0.05);
            this.end = this.round(this.clamp(this.end, this.start + 0.05, maxDuration));
            this.syncWire();
        },
        loadCut(start, end) {
            const safeStart = this.round(Math.max(0, Number(start || 0)));
            const maxDuration = this.duration || Math.max(Number(end || 0), safeStart + 0.05);
            this.start = safeStart;
            this.end = this.round(this.clamp(Number(end || 0), safeStart + 0.05, maxDuration));
            this.jumpTo(this.start);
            this.syncWire();
            this.$nextTick(() => this.fitSelectionToViewport());
        }
    }"
>
    <style>
        .timeline-scroll::-webkit-scrollbar {
            height: 8px;
        }
        .timeline-scroll::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.04);
            border-radius: 4px;
        }
        .timeline-scroll::-webkit-scrollbar-thumb {
            background: rgba(100, 116, 139, 0.7);
            border-radius: 4px;
        }
        .timeline-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.9);
        }
        /* Firefox */
        .timeline-scroll {
            scrollbar-width: thin;
            scrollbar-color: rgba(100, 116, 139, 0.7) rgba(255, 255, 255, 0.04);
        }
    </style>
    <div class="space-y-6">
        <x-studio.page-header
            eyebrow="Editor"
            :title="$video->title ?? 'Editor de cortes'"
            subtitle="Use a timeline para marcar com precisão os pontos do corte e revisar a fala em paralelo."
        >
            <x-slot:meta>
                <flux:badge>{{ $video->status?->label ?? '—' }}</flux:badge>
            </x-slot:meta>
        </x-studio.page-header>

        @if($activeJobId)
            <div class="mb-6" wire:key="render-progress-{{ $activeJobId }}">
                @include('livewire.videos._progress', ['jobId' => $activeJobId, 'wsUrl' => $wsUrl])
            </div>
        @endif

        <div class="min-w-0 space-y-8">
            <div class="min-w-0 space-y-5">
                <div class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900/70 p-5 min-w-0">
                <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_300px] gap-4 items-start">
                    <div>
                        @if($playerUrl)
                            <video
                                x-init="window.initAdaptiveVideoPlayer($el); registerPlayer($el)"
                                @if($hlsUrl) data-hls-src="{{ $hlsUrl }}" @endif
                                @if($playerUrl) data-fallback-src="{{ $playerUrl }}" @endif
                                @if($playerUrl && ! $hlsUrl) src="{{ $playerUrl }}" @endif
                                controls
                                class="w-full rounded-xl bg-black object-contain"
                                style="max-height: 420px;"
                            ></video>
                        @else
                            <div class="w-full rounded-xl border border-dashed border-slate-700 aspect-video flex items-center justify-center text-slate-500">
                                Video original ainda nao disponivel.
                            </div>
                        @endif
                    </div>
                    <x-videos.karaoke-panel />
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-center gap-6">
                    <div class="flex flex-col items-center justify-center min-w-[5rem]">
                        <span class="text-[10px] uppercase font-semibold tracking-wider text-sky-500 dark:text-sky-400">Playhead</span>
                        <span class="mt-0.5 text-sm font-medium tabular-nums text-slate-100" x-text="formatClock(current)"></span>
                    </div>
                    <div class="flex flex-col items-center justify-center min-w-[5rem]">
                        <span class="text-[10px] uppercase font-semibold tracking-wider text-emerald-500 dark:text-emerald-400">Início</span>
                        <span class="mt-0.5 text-sm font-medium tabular-nums text-slate-100" x-text="formatClock(start)"></span>
                    </div>
                    <div class="flex flex-col items-center justify-center min-w-[5rem]">
                        <span class="text-[10px] uppercase font-semibold tracking-wider text-amber-500 dark:text-amber-400">Fim</span>
                        <span class="mt-0.5 text-sm font-medium tabular-nums text-slate-100" x-text="formatClock(end)"></span>
                    </div>
                    <div class="flex flex-col items-center justify-center min-w-[5rem]">
                        <span class="text-[10px] uppercase font-semibold tracking-wider text-zinc-500 dark:text-zinc-400">Duração</span>
                        <span class="mt-0.5 text-sm font-medium tabular-nums text-slate-100" x-text="formatClock(Math.max(0, end - start))"></span>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    <flux:button variant="filled" size="sm" class="cursor-pointer" x-on:click="jumpTo(start)">ir para início</flux:button>
                    <flux:button variant="filled" size="sm" class="cursor-pointer" x-on:click="jumpTo(end)">ir para fim</flux:button>
                    <flux:button variant="filled" size="sm" class="cursor-pointer" x-on:click="nudgePlayhead(-0.05)">-50ms</flux:button>
                    <flux:button variant="filled" size="sm" class="cursor-pointer" x-on:click="nudgePlayhead(0.05)">+50ms</flux:button>
                    <flux:button variant="filled" size="sm" class="cursor-pointer" x-on:click="setStartFromCurrent()">marcar início no playhead</flux:button>
                    <flux:button variant="filled" size="sm" class="cursor-pointer" x-on:click="setEndFromCurrent()">marcar fim no playhead</flux:button>
                </div>

                <div class="mt-6 rounded-xl border border-slate-800 bg-slate-950 text-slate-100 p-4 min-w-0">
                    <div class="flex flex-wrap items-center justify-between gap-4 mb-3">
                        <div>
                            <div class="text-sm font-medium">Timeline do corte</div>
                            <div class="text-xs text-slate-400">Clique para mover o playhead, arraste as alças para ajustar início e fim, use zoom para refinar.</div>
                        </div>
                        <label class="flex items-center gap-3 text-sm">
                            <span class="text-slate-400">Zoom</span>
                            <input type="range" min="0.1" max="5" step="0.05" x-model.number="zoom" class="accent-cyan-400 w-40">
                            <span class="w-10 text-right tabular-nums text-slate-300" x-text="zoom.toFixed(1) + 'x'"></span>
                        </label>
                    </div>

                    <div
                        x-ref="timelineViewport"
                        class="timeline-scroll relative overflow-x-auto rounded-xl border border-slate-800 bg-slate-950 pb-2"
                        x-on:wheel.prevent="adjustZoom($event)"
                    >
                        <div
                            x-ref="timelineInner"
                            class="relative"
                            :style="`width:${timelineWidth}px`"
                        >
                            <div class="relative h-10 cursor-pointer border-b border-slate-800 bg-[linear-gradient(180deg,rgba(255,255,255,0.04),rgba(255,255,255,0))]"
                                 x-on:click="seekFromPointer($event)">
                                <template x-for="mark in rulerMarks()" :key="`mark-${mark.time}`">
                                    <div
                                        class="absolute top-0"
                                        :style="`left:${timeToPx(mark.time)}px`"
                                    >
                                        <div class="w-px bg-slate-500/80" :style="`height:${mark.height}px`"></div>
                                        <div
                                            x-show="mark.label"
                                            class="absolute top-0 left-2 text-[10px] tracking-wide text-slate-400 tabular-nums"
                                            x-text="formatRuler(mark.time)"
                                        ></div>
                                    </div>
                                </template>
                            </div>

                            <div
                                class="relative h-28 cursor-pointer overflow-hidden rounded-b-xl bg-slate-900"
                                style="height: 7rem;"
                                x-on:click="seekFromPointer($event)"
                            >
                                <template x-if="thumbTrack.length">
                                    <div class="absolute inset-0 flex">
                                        <template x-for="thumb in thumbTrack" :key="thumb.id">
                                            <div
                                                class="absolute inset-y-0 border-r border-black/30"
                                                :style="`left:${thumb.left};width:${thumb.width}`"
                                            >
                                                <video
                                                    :src="player ? (player.currentSrc || player.src) : ''"
                                                    muted
                                                    playsinline
                                                    preload="metadata"
                                                    class="h-full w-full object-cover opacity-80 select-none pointer-events-none"
                                                    x-init="primeThumb($el, thumb.time)"
                                                ></video>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <div
                                    x-show="!thumbTrack.length"
                                    class="absolute inset-0 bg-[linear-gradient(135deg,rgba(34,211,238,0.18),rgba(34,211,238,0.05)),repeating-linear-gradient(90deg,rgba(255,255,255,0.03)_0px,rgba(255,255,255,0.03)_20px,transparent_20px,transparent_40px)]"
                                ></div>

                                <div class="absolute inset-y-0 left-0 bg-black/20 rounded-r-xl"
                                     :style="`width:${timeToPx(start)}px`"></div>
                                <div class="absolute inset-y-0 bg-black/20 rounded-l-xl"
                                     :style="`left:${timeToPx(end)}px;width:${Math.max(0, timelineWidth - timeToPx(end))}px`"></div>

                                <div class="absolute inset-y-5 rounded-xl border border-cyan-300/70 bg-cyan-400/15"
                                     :style="`left:${timeToPx(start)}px;width:${Math.max(8, timeToPx(end) - timeToPx(start))}px`"></div>

                                <div class="absolute inset-y-3 w-[3px] bg-white shadow-[0_0_0_1px_rgba(255,255,255,0.35)]"
                                     :style="`left:${timeToPx(current)}px`"
                                     x-on:pointerdown.stop="beginDrag('playhead', $event)">
                                    <div class="absolute -top-2 left-1/2 -translate-x-1/2 h-5 w-5 rounded-full border-2 border-white bg-cyan-400 shadow-lg"></div>
                                </div>

                                <button
                                    type="button"
                                    class="absolute inset-y-4 w-4 -translate-x-1/2 rounded-full border border-emerald-300 bg-emerald-400/90 shadow-lg"
                                    :style="`left:${timeToPx(start)}px`"
                                    x-on:pointerdown.stop="beginDrag('start', $event)"
                                    aria-label="Arrastar início"
                                ></button>

                                <button
                                    type="button"
                                    class="absolute inset-y-4 w-4 -translate-x-1/2 rounded-full border border-amber-300 bg-amber-400/90 shadow-lg"
                                    :style="`left:${timeToPx(end)}px`"
                                    x-on:pointerdown.stop="beginDrag('end', $event)"
                                    aria-label="Arrastar fim"
                                ></button>

                                <div class="absolute left-4 top-4 rounded-full bg-black/50 px-3 py-1 text-[11px] text-slate-200">
                                    timeline com preview
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
                        <div class="mb-1 text-xs uppercase tracking-wide text-slate-500">Início</div>
                        <input type="number" step="0.001" x-model.number="start" x-on:change="syncStartInput()" class="w-full bg-transparent text-sm font-medium text-slate-100 outline-none">
                    </label>
                    <label class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
                        <div class="mb-1 text-xs uppercase tracking-wide text-slate-500">Fim</div>
                        <input type="number" step="0.001" x-model.number="end" x-on:change="syncEndInput()" class="w-full bg-transparent text-sm font-medium text-slate-100 outline-none">
                    </label>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
                        <div class="mb-1 text-xs uppercase tracking-wide text-slate-500">Duração</div>
                        <div class="text-sm font-medium tabular-nums text-slate-100" x-text="formatClock(Math.max(0, end - start))"></div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <flux:button variant="primary" size="sm" icon="plus" class="cursor-pointer" x-on:click="addCutFromTimeline()">Adicionar corte</flux:button>
                    <flux:button :href="route('videos.schedule', $video)" variant="subtle" size="sm" icon="calendar-days" class="cursor-pointer" wire:navigate>Agendar postagens</flux:button>
                </div>
            </div>
        </div>

        {{-- Transcrição do vídeo --}}
        @if(isset($transcript) && $transcript)
            <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-5 min-w-0"
                 x-data="{ transcriptOpen: false }">
                <button
                    type="button"
                    class="w-full flex items-center justify-between text-left"
                    x-on:click="transcriptOpen = !transcriptOpen">
                    <div>
                        <flux:heading size="sm">Transcrição por tempo</flux:heading>
                        <flux:text class="mt-0.5 text-xs text-slate-500">Ajustes aqui impactam diretamente o resultado da legenda.</flux:text>
                    </div>
                    <svg class="h-4 w-4 text-slate-400 transition-transform" :class="transcriptOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div x-show="transcriptOpen" x-collapse class="mt-3">
                    <x-videos.timed-words-editor
                        title="Transcrição por tempo"
                        subtitle="Ajustes aqui impactam diretamente o resultado da legenda."
                        save-label="Salvar sincronia"
                        :save-action="'$wire.saveTimedWords(timedWords)'"
                    />
                </div>
            </div>
        @endif

        {{-- Sugestão da IA (abaixo da transcrição) --}}
        <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-5">
            <flux:heading size="sm">Sugestão opcional da IA</flux:heading>
            <flux:text class="mt-1 text-sm text-slate-400">
                Se quiser acelerar, a IA ainda pode sugerir tempos iniciais. Depois voce ajusta tudo na timeline.
            </flux:text>
            <div class="mt-3 flex flex-col gap-3">
                <flux:input wire:model="userPrompt" placeholder="Ex: foque nos melhores ganchos (opcional)" />
                <div>
                    <flux:button wire:click="recommend" variant="filled" size="sm" icon="sparkles" class="cursor-pointer">
                        <span wire:loading.remove wire:target="recommend">Sugerir cortes com IA</span>
                        <span wire:loading wire:target="recommend">Pensando...</span>
                    </flux:button>
                </div>
            </div>
        </div>

        {{-- Seção de cortes --}}
        <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-5 min-w-0"
             x-data="{ selectedCuts: @entangle('selectedCuts') }">
            <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
                <div class="flex items-center gap-3">
                    @if($cuts->isNotEmpty())
                        <input
                            type="checkbox"
                            class="h-4 w-4 rounded accent-cyan-500 cursor-pointer"
                            x-data="{ allUuids: @js($cuts->pluck('uuid')->all()) }"
                            :checked="selectedCuts.length === allUuids.length && allUuids.length > 0"
                            x-on:click="selectedCuts.length === allUuids.length ? selectedCuts = [] : selectedCuts = [...allUuids]">
                    @endif
                    <flux:heading size="lg">Cortes ({{ $cuts->count() }})</flux:heading>
                </div>
                <div class="flex items-center gap-2">
                    @if($cuts->isNotEmpty())
                        <button
                            type="button"
                            class="inline-flex items-center justify-center gap-2 px-3 py-1.5 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20 rounded-lg transition-colors"
                            x-show="selectedCuts.length > 0"
                            wire:click="deleteSelected">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                              <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                            <span wire:loading.remove wire:target="deleteSelected" x-text="`Apagar (${selectedCuts.length})`"></span>
                            <span wire:loading wire:target="deleteSelected">Apagando...</span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center gap-2 px-3 py-1.5 text-sm font-medium text-zinc-700 bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 rounded-lg transition-colors"
                            x-show="selectedCuts.length > 0"
                            wire:click="renderSelected">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125m-9.75 0v1.5c0 .621.504 1.125 1.125 1.125m18.375-3.75v1.5c0 .621-.504 1.125-1.125 1.125m0 0v1.5c0 .621-.504 1.125-1.125 1.125m0 0h-7.5c-.621 0-1.125-.504-1.125-1.125" />
                            </svg>
                            <span wire:loading.remove wire:target="renderSelected" x-text="`Renderizar (${selectedCuts.length})`"></span>
                            <span wire:loading wire:target="renderSelected">Enviando...</span>
                        </button>
                        <button
                            type="button"
                            wire:click="renderCuts"
                            class="inline-flex items-center justify-center gap-2 px-3 py-1.5 text-sm font-medium text-white bg-cyan-600 hover:bg-cyan-700 rounded-lg transition-colors shadow-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125m-9.75 0v1.5c0 .621.504 1.125 1.125 1.125m18.375-3.75v1.5c0 .621-.504 1.125-1.125 1.125m0 0v1.5c0 .621-.504 1.125-1.125 1.125m0 0h-7.5c-.621 0-1.125-.504-1.125-1.125" />
                            </svg>
                            <span wire:loading.remove wire:target="renderCuts">Renderizar todos</span>
                            <span wire:loading wire:target="renderCuts">Enviando...</span>
                        </button>
                    @endif
                </div>
            </div>

            @if($cuts->isEmpty())
                <flux:text class="text-slate-500">Nenhum corte ainda. Marque o range na timeline e adicione o corte.</flux:text>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach($cuts as $cut)
                    @php($rendered = $cut->files->firstWhere('type', $cut->type))
                    <div class="flex flex-col gap-3 rounded-xl border border-slate-800 bg-slate-950/70 p-4"
                         wire:key="cut-{{ $cut->uuid }}"
                         x-data="{ isEditing: false }">

                        {{-- Checkbox + badges + actions --}}
                        <div class="flex items-start gap-2">
                            <input
                                type="checkbox"
                                class="mt-1 h-4 w-4 rounded accent-cyan-500 cursor-pointer flex-shrink-0"
                                value="{{ $cut->uuid }}"
                                x-model="selectedCuts"
                            >
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-1 flex-wrap">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <flux:badge color="{{ $cut->source === 'ai' ? 'purple' : 'zinc' }}" size="sm">{{ $cut->name }}</flux:badge>
                                        @if($cut->source === 'ai')<flux:badge size="sm" color="purple">IA</flux:badge>@endif
                                        @if($cut->rendered_at)<flux:badge size="sm" color="green">renderizado</flux:badge>@endif
                                    </div>
                                    <div class="flex items-center gap-1 flex-shrink-0">
                                        <flux:button variant="ghost" icon="pencil-square" class="cursor-pointer"
                                            x-show="!isEditing"
                                            x-on:click="isEditing = true; loadCut({{ $cut->start_seconds }}, {{ $cut->end_seconds }})">
                                        </flux:button>
                                        <flux:button variant="ghost" icon="x-mark" class="cursor-pointer"
                                            x-show="isEditing"
                                            x-on:click="isEditing = false">
                                        </flux:button>
                                    </div>
                                </div>
                                @if($cut->reason)
                                    <p class="mt-1 text-xs text-slate-500">{{ $cut->reason }}</p>
                                @endif
                            </div>
                        </div>

                        {{-- Inputs de tempo --}}
                        <div class="grid grid-cols-[1fr_1fr] gap-2 items-end">
                            <flux:input type="number" step="0.001" wire:model="cutEdits.{{ $cut->uuid }}.start" label="Início" size="sm" x-bind:disabled="!isEditing" />
                            <flux:input type="number" step="0.001" wire:model="cutEdits.{{ $cut->uuid }}.end" label="Fim" size="sm" x-bind:disabled="!isEditing" />
                        </div>

                        <p class="-mt-1 text-xs tabular-nums text-slate-400">{{ number_format($cut->duration_seconds, 3) }}s</p>

                        <div class="mt-2" x-show="isEditing" x-collapse>
                            <flux:button variant="filled" class="w-full cursor-pointer"
                                wire:click="saveCutEdit('{{ $cut->uuid }}')"
                                x-on:click="isEditing = false">
                                <span wire:loading.remove wire:target="saveCutEdit">Salvar Alterações</span>
                                <span wire:loading wire:target="saveCutEdit">Salvando...</span>
                            </flux:button>
                        </div>

                        {{-- Preview do vídeo renderizado --}}
                        @if($rendered)
                            <video src="{{ $rendered->temporaryUrl(120) }}" controls x-init="$el.volume = 0.2"
                                   class="mx-auto aspect-[9/16] max-h-80 w-full rounded-xl bg-black"></video>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        </div>
    </div>
</section>
