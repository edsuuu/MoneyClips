// Editor de reframe/crop com keyframes (/estudio-de-cortes). Tudo roda no
// client: o <video> fonte alimenta um <canvas> 9:16 via drawImage a cada
// frame (rAF), com crop interpolado entre keyframes. O Livewire só recebe
// o estado no Salvar (saveEdit) — nunca re-renderiza durante a edição
// (a ilha inteira fica sob wire:ignore).
//
// Regra de performance: tudo que é tocado a 60fps vive em propriedades
// `_underscore` atribuídas dentro do init() — fora do proxy reativo do
// Alpine. Estado reativo é só o de frequência humana (cliques, keyframes).

const OUT_W = 1080;
const OUT_H = 1920;
const MAX_KEYFRAMES = 120;
const MIN_SIZE = 0.05; // tamanho mínimo da região no arrasto (UX)
const T_EPSILON = 0.05; // keyframes mais próximos que isto são o mesmo

// slots em px do canvas de saída; fit 'cover' preenche o slot, 'contain'
// centraliza com barras da cor de fundo; lock trava o aspect do crop na
// fonte ('slot' = aspect do slot, 'source' = aspect da fonte, 'free' = livre).
const MODES = {
    vertical: { label: 'Vertical', fit: 'cover', lock: 'slot', slots: [{ x: 0, y: 0, w: OUT_W, h: OUT_H }] },
    split: {
        label: 'Dividido', fit: 'cover', lock: 'slot',
        slots: [{ x: 0, y: 0, w: OUT_W, h: OUT_H / 2 }, { x: 0, y: OUT_H / 2, w: OUT_W, h: OUT_H / 2 }],
    },
    trio: {
        label: 'Trio', fit: 'cover', lock: 'slot',
        slots: [0, 1, 2].map((i) => ({ x: 0, y: (OUT_H / 3) * i, w: OUT_W, h: OUT_H / 3 })),
    },
    spotlight: { label: 'Spotlight', fit: 'contain', lock: 'free', slots: [{ x: 0, y: 0, w: OUT_W, h: OUT_H }] },
    centered: { label: 'Centrado', fit: 'contain', lock: 'source', slots: [{ x: 0, y: 0, w: OUT_W, h: OUT_H }] },
};

const REGION_LABELS = { 1: ['Região'], 2: ['Topo', 'Base'], 3: ['Topo', 'Meio', 'Base'] };

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
const round4 = (value) => Math.round(value * 10000) / 10000;
const round3 = (value) => Math.round(value * 1000) / 1000;

export function reframeEditor(initial) {
    return {
        // ── Persistente (espelha reframe_edits) ──
        editId: initial.editId,
        videoUrl: initial.videoUrl,
        mode: initial.mode,
        keyframes: initial.keyframes,
        settings: initial.settings,

        // ── Sessão/UI (reativo, frequência humana) ──
        duration: 0,
        currentTime: 0,
        playing: false,
        dirty: false,
        saving: false,
        selectedKf: null,
        activeRegion: 0,

        init() {
            this._video = this.$refs.video;
            this._canvas = this.$refs.canvas;
            this._ctx = this._canvas.getContext('2d');
            this._drag = null;
            this._dragLive = null;
            this._boxes = null;
            this._lastDrawnT = -1;
            this._needsDraw = true;

            this._onMetadata = () => this._handleMetadata();
            this._onTime = () => { this.currentTime = this._video.currentTime; };
            this._onPlay = () => { this.playing = true; };
            this._onPause = () => { this.playing = false; };
            this._onError = () => this.recoverVideoUrl();
            this._video.addEventListener('loadedmetadata', this._onMetadata);
            this._video.addEventListener('timeupdate', this._onTime);
            this._video.addEventListener('play', this._onPlay);
            this._video.addEventListener('pause', this._onPause);
            this._video.addEventListener('error', this._onError);

            this._onVisibility = () => { this._needsDraw = true; };
            document.addEventListener('visibilitychange', this._onVisibility);
            this._onBeforeUnload = (event) => {
                if (!this.dirty) return;
                event.preventDefault();
                event.returnValue = '';
            };
            window.addEventListener('beforeunload', this._onBeforeUnload);

            // Renova a URL presigned antes do TTL de 30 min expirar.
            this._urlTimer = setInterval(() => this.recoverVideoUrl(), 25 * 60 * 1000);

            if (this.videoUrl) this._video.src = this.videoUrl;
            this._tick();
        },

        destroy() {
            cancelAnimationFrame(this._raf);
            clearInterval(this._urlTimer);
            document.removeEventListener('visibilitychange', this._onVisibility);
            window.removeEventListener('beforeunload', this._onBeforeUnload);
            this._video.pause();
        },

        // ── Transporte ──
        togglePlay() {
            if (!this.duration) return;
            this._video.paused ? this._video.play() : this._video.pause();
        },

        seek(seconds) {
            if (!this.duration) return;
            const t = clamp(seconds, 0, this.duration);
            this._video.currentTime = t;
            this.currentTime = t;
            this._needsDraw = true;
        },

        seekFromRuler(event) {
            const rect = this.$refs.ruler.getBoundingClientRect();
            this.seek(((event.clientX - rect.left) / rect.width) * this.duration);
        },

        timeLabel() {
            const fmt = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
            return `${fmt(this.currentTime)} / ${fmt(this.duration)}`;
        },

        // ── Keyframes ──
        addKeyframeAtCurrentTime() {
            const index = this._ensureKeyframeAtCurrentTime();
            if (index === null) return;
            this.selectedKf = index;
            this.dirty = true;
            this._needsDraw = true;
        },

        selectKeyframe(index) {
            this.selectedKf = index;
            this.seek(this.keyframes[index].t);
        },

        deleteSelectedKeyframe() {
            if (this.selectedKf === null || this.keyframes.length <= 1) return;
            this.keyframes.splice(this.selectedKf, 1);
            this.selectedKf = null;
            this.dirty = true;
            this._needsDraw = true;
        },

        // ── Modos / regiões ──
        setMode(mode) {
            if (mode === this.mode || !MODES[mode]) return;
            const discards = this.dirty || this.keyframes.length > 1;
            if (discards && !confirm('Trocar o modo descarta os keyframes atuais. Continuar?')) return;

            this.mode = mode;
            this.activeRegion = 0;
            this.keyframes = this.duration ? [this._defaultKeyframe()] : [];
            this.selectedKf = this.keyframes.length ? 0 : null;
            this.dirty = true;
            this._boxes = null;
            this._needsDraw = true;
        },

        setActiveRegion(index) {
            this.activeRegion = index;
        },

        modeOptions() {
            return Object.entries(MODES).map(([value, def]) => ({ value, label: def.label }));
        },

        modeSlots() {
            return MODES[this.mode].slots;
        },

        regionTabs() {
            const labels = REGION_LABELS[MODES[this.mode].slots.length];
            return labels.map((label, i) => ({ i, label }));
        },

        isContain() {
            return MODES[this.mode].fit === 'contain';
        },

        markDirty() {
            this.dirty = true;
            this._needsDraw = true;
        },

        // ── Máquina de drag/resize da caixa de crop ──
        onPointerDown(event, type) {
            if (!this.duration || this._drag) return;
            event.preventDefault();
            event.target.setPointerCapture(event.pointerId);

            const kfIndex = this._ensureKeyframeAtCurrentTime();
            if (kfIndex === null) return;
            this.selectedKf = kfIndex;

            const region = this.keyframes[kfIndex].regions[this.activeRegion];
            this._drag = {
                type,
                pointerId: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                start: { ...region },
                kfIndex,
            };
            this._dragLive = { ...region };
        },

        onPointerMove(event) {
            if (!this._drag || event.pointerId !== this._drag.pointerId) return;
            const rect = this.$refs.overlay.getBoundingClientRect();
            const dx = (event.clientX - this._drag.startX) / rect.width;
            const dy = (event.clientY - this._drag.startY) / rect.height;
            const start = this._drag.start;

            this._dragLive = this._drag.type === 'move'
                ? {
                    ...start,
                    x: clamp(start.x + dx, 0, 1 - start.w),
                    y: clamp(start.y + dy, 0, 1 - start.h),
                }
                : this._resize(start, this._drag.type, dx, dy);
            this._needsDraw = true;
        },

        onPointerUp(event) {
            if (!this._drag || event.pointerId !== this._drag.pointerId) return;
            const { kfIndex } = this._drag;
            const committed = this._dragLive;
            this._drag = null;
            this._dragLive = null;

            this.keyframes[kfIndex].regions[this.activeRegion] = {
                x: round4(committed.x),
                y: round4(committed.y),
                w: round4(committed.w),
                h: round4(committed.h),
            };
            this.dirty = true;
            this._needsDraw = true;
        },

        // ── Persistência (única ponte com o Livewire) ──
        async save() {
            if (this.saving || !this.duration) return;
            this.saving = true;
            try {
                const id = await this.$wire.saveEdit({
                    editId: this.editId,
                    mode: this.mode,
                    keyframes: this.keyframes,
                    settings: this.settings,
                    sourceMeta: {
                        width: this._video.videoWidth,
                        height: this._video.videoHeight,
                        duration: this.duration,
                    },
                });
                if (id !== null) {
                    this.editId = id;
                    this.dirty = false; // toast de sucesso vem do servidor
                }
            } finally {
                this.saving = false;
            }
        },

        async recoverVideoUrl() {
            try {
                const url = await this.$wire.refreshUrl();
                if (!url) return;
                const t = this._video.currentTime;
                const wasPlaying = !this._video.paused;
                this.videoUrl = url;
                this._video.src = url;
                this._video.addEventListener('loadedmetadata', () => {
                    this._video.currentTime = t;
                    if (wasPlaying) this._video.play();
                }, { once: true });
            } catch {
                // presigned indisponível — o listener de error tenta de novo
            }
        },

        // ── Internos ──
        _handleMetadata() {
            this.duration = this._video.duration;
            // Wrapper com o aspect da fonte: o box do overlay coincide com o
            // vídeo exibido (sem letterbox do object-contain na conta).
            this.$refs.stage.style.aspectRatio = `${this._video.videoWidth} / ${this._video.videoHeight}`;
            if (!this.keyframes.length) {
                this.keyframes = [this._defaultKeyframe()];
                this.selectedKf = 0;
            }
            this._needsDraw = true;
        },

        _tick() {
            this._raf = requestAnimationFrame(() => this._tick());
            if (document.hidden || this._video.readyState < 2) return;
            const t = this._video.currentTime;
            if (!this._needsDraw && t === this._lastDrawnT && !this._drag) return;
            this._lastDrawnT = t;
            this._needsDraw = false;
            this._drawFrame(t);
            this._syncOverlay(t);
        },

        _drawFrame(t) {
            const regions = this._liveRegions(t);
            if (!regions) return;
            const { slots, fit } = MODES[this.mode];
            const ctx = this._ctx;
            const vw = this._video.videoWidth;
            const vh = this._video.videoHeight;

            if (fit === 'contain') {
                ctx.fillStyle = this.settings.background;
                ctx.fillRect(0, 0, OUT_W, OUT_H);
            }

            slots.forEach((slot, i) => {
                const r = regions[i];
                const sx = r.x * vw;
                const sy = r.y * vh;
                const sw = r.w * vw;
                const sh = r.h * vh;
                if (fit === 'contain') {
                    const scale = Math.min(slot.w / sw, slot.h / sh);
                    const dw = sw * scale;
                    const dh = sh * scale;
                    ctx.drawImage(this._video, sx, sy, sw, sh, slot.x + (slot.w - dw) / 2, slot.y + (slot.h - dh) / 2, dw, dh);
                } else {
                    ctx.drawImage(this._video, sx, sy, sw, sh, slot.x, slot.y, slot.w, slot.h);
                }
            });
        },

        _syncOverlay(t) {
            const overlay = this.$refs.overlay;
            const regions = this._liveRegions(t);
            if (!overlay || !regions) return;

            if (!this._boxes || this._boxes.length !== regions.length) {
                this._boxes = [...overlay.querySelectorAll('[data-region-box]')];
            }
            if (this._boxes.length !== regions.length) return; // x-for ainda montando

            this._boxes.forEach((el, i) => {
                const r = regions[i];
                el.style.left = `${r.x * 100}%`;
                el.style.top = `${r.y * 100}%`;
                el.style.width = `${r.w * 100}%`;
                el.style.height = `${r.h * 100}%`;
            });

            if (this.$refs.playhead && this.duration) {
                this.$refs.playhead.style.left = `${(t / this.duration) * 100}%`;
            }
        },

        /** Regiões no tempo t, com a região ativa substituída pelo drag ao vivo. */
        _liveRegions(t) {
            const regions = this.regionsAt(t);
            if (!regions) return null;
            if (!this._dragLive) return regions;
            const live = regions.map((r) => ({ ...r }));
            live[this.activeRegion] = this._dragLive;
            return live;
        },

        /** Interpolação linear entre os dois keyframes vizinhos de t. */
        regionsAt(t) {
            const kfs = this.keyframes;
            if (!kfs.length) return null;
            if (t <= kfs[0].t) return kfs[0].regions;
            const last = kfs[kfs.length - 1];
            if (t >= last.t) return last.regions;

            let i = 1;
            while (kfs[i].t < t) i++;
            const a = kfs[i - 1];
            const b = kfs[i];
            const f = (t - a.t) / (b.t - a.t);

            return a.regions.map((ra, j) => {
                const rb = b.regions[j];
                return {
                    x: ra.x + (rb.x - ra.x) * f,
                    y: ra.y + (rb.y - ra.y) * f,
                    w: ra.w + (rb.w - ra.w) * f,
                    h: ra.h + (rb.h - ra.h) * f,
                };
            });
        },

        /** Keyframe em currentTime (±epsilon), criando por interpolação se preciso. */
        _ensureKeyframeAtCurrentTime() {
            const t = round3(this._video.currentTime);
            const existing = this.keyframes.findIndex((kf) => Math.abs(kf.t - t) < T_EPSILON);
            if (existing !== -1) return existing;

            if (this.keyframes.length >= MAX_KEYFRAMES) {
                this.$dispatch('toast', { message: `Limite de ${MAX_KEYFRAMES} keyframes atingido.`, variant: 'warning' });
                return null;
            }

            const regions = this.regionsAt(t).map((r) => ({ ...r }));
            const keyframe = { t, regions };
            const index = this.keyframes.findIndex((kf) => kf.t > t);
            if (index === -1) {
                this.keyframes.push(keyframe);
                return this.keyframes.length - 1;
            }
            this.keyframes.splice(index, 0, keyframe);
            return index;
        },

        _defaultKeyframe() {
            return {
                t: 0,
                regions: MODES[this.mode].slots.map((slot) => this._defaultRegion(slot)),
            };
        },

        /** Região default: crop máximo centrado honrando o lock do modo. */
        _defaultRegion(slot) {
            const vw = this._video.videoWidth;
            const vh = this._video.videoHeight;
            let w = 1;
            let h = 1;
            if (MODES[this.mode].lock === 'slot') {
                const aspect = slot.w / slot.h;
                if (vw / vh >= aspect) {
                    w = (aspect * vh) / vw;
                } else {
                    h = vw / (aspect * vh);
                }
            }
            // 'free' e 'source': frame cheio (a fonte inteira já é o crop).
            return { x: round4((1 - w) / 2), y: round4((1 - h) / 2), w: round4(w), h: round4(h) };
        },

        /** Aspect (em px da fonte) que o crop da região ativa deve manter. */
        _lockAspect() {
            const mode = MODES[this.mode];
            if (mode.lock === 'free') return null;
            if (mode.lock === 'source') return this._video.videoWidth / this._video.videoHeight;
            const slot = mode.slots[this.activeRegion];
            return slot.w / slot.h;
        },

        /** Resize por canto: âncora no canto oposto + aspect lock + clamps. */
        _resize(start, handle, dx, dy) {
            const vw = this._video.videoWidth;
            const vh = this._video.videoHeight;
            const west = handle.includes('w');
            const north = handle.includes('n');

            const anchorX = west ? start.x + start.w : start.x;
            const anchorY = north ? start.y + start.h : start.y;
            const cornerX = clamp((west ? start.x : start.x + start.w) + dx, 0, 1);
            const cornerY = clamp((north ? start.y : start.y + start.h) + dy, 0, 1);

            let w = Math.abs(cornerX - anchorX);
            let h = Math.abs(cornerY - anchorY);
            const maxW = west ? anchorX : 1 - anchorX;
            const maxH = north ? anchorY : 1 - anchorY;
            const aspect = this._lockAspect();

            if (aspect !== null) {
                // Dirige pela largura; h decorre do aspect (espaço normalizado
                // é anisotrópico: h_norm = w_norm * vw / (aspect * vh)).
                const wFromMaxH = (maxH * aspect * vh) / vw;
                w = clamp(Math.max(w, MIN_SIZE), MIN_SIZE, Math.min(maxW, wFromMaxH));
                h = (w * vw) / (aspect * vh);
            } else {
                w = clamp(Math.max(w, MIN_SIZE), MIN_SIZE, maxW);
                h = clamp(Math.max(h, MIN_SIZE), MIN_SIZE, maxH);
            }

            return {
                x: west ? anchorX - w : anchorX,
                y: north ? anchorY - h : anchorY,
                w,
                h,
            };
        },
    };
}
