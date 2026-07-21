import { ReframeGeometry } from './ReframeGeometry';
import { ReframeModes } from './ReframeModes';
import { ReframeRenderer } from './ReframeRenderer';
import type {
    DragHandle,
    DragState,
    Keyframe,
    Region,
    ReframePayload,
    ReframeSettings,
    Slot,
} from './ReframeTypes';
import { ClientLogger } from '../Support/ClientLogger';

export class ReframeEditor {
    public static readonly MAX_KEYFRAMES = 120;

    public static readonly T_EPSILON = 0.05;

    public editId: number | null;

    public videoUrl: string | null;

    public mode: string;

    public keyframes: Keyframe[];

    public settings: ReframeSettings;

    public duration = 0;

    public currentTime = 0;

    public playing = false;

    public dirty = false;

    public saving = false;

    public selectedKf: number | null = null;

    public activeRegion = 0;

    public $refs!: Record<string, HTMLElement | undefined>;

    public $wire!: {
        saveEdit: (payload: unknown) => Promise<number | null>;
        refreshUrl: () => Promise<string | null>;
    };

    public $dispatch!: (event: string, detail: unknown) => void;

    private _video!: HTMLVideoElement;

    private _renderer!: ReframeRenderer;

    private _drag: DragState | null = null;

    private _dragLive: Region | null = null;

    private _boxes: HTMLElement[] | null = null;

    private _lastDrawnT = -1;

    private _needsDraw = true;

    private _raf = 0;

    private _urlTimer = 0;

    private _onVisibility!: () => void;

    private _onBeforeUnload!: (event: BeforeUnloadEvent) => void;

    public constructor(initial: ReframePayload) {
        this.editId = initial.editId;
        this.videoUrl = initial.videoUrl;
        this.mode = initial.mode;
        this.keyframes = initial.keyframes;
        this.settings = initial.settings;
    }

    public init(): void {
        this._video = this.$refs.video as HTMLVideoElement;
        const canvas = this.$refs.canvas as HTMLCanvasElement;
        this._renderer = new ReframeRenderer(
            canvas.getContext('2d') as CanvasRenderingContext2D,
            this._video,
        );

        this._video.addEventListener('loadedmetadata', () => this.handleMetadata());
        this._video.addEventListener('timeupdate', () => {
            this.currentTime = this._video.currentTime;
        });
        this._video.addEventListener('play', () => {
            this.playing = true;
        });
        this._video.addEventListener('pause', () => {
            this.playing = false;
        });
        this._video.addEventListener('error', () => void this.recoverVideoUrl());

        this._onVisibility = () => {
            this._needsDraw = true;
        };
        document.addEventListener('visibilitychange', this._onVisibility);

        this._onBeforeUnload = (event: BeforeUnloadEvent) => {
            if (!this.dirty) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', this._onBeforeUnload);

        this._urlTimer = window.setInterval(() => void this.recoverVideoUrl(), 25 * 60 * 1000);

        if (this.videoUrl) {
            this._video.src = this.videoUrl;
        }

        this.tick();
    }

    public destroy(): void {
        cancelAnimationFrame(this._raf);
        clearInterval(this._urlTimer);
        document.removeEventListener('visibilitychange', this._onVisibility);
        window.removeEventListener('beforeunload', this._onBeforeUnload);
        this._video.pause();
    }

    public togglePlay(): void {
        if (!this.duration) return;

        if (this._video.paused) {
            this._video
                .play()
                .catch((error: unknown) =>
                    ClientLogger.send('warning', `play() recusado: ${String(error)}`),
                );

            return;
        }

        this._video.pause();
    }

    public seek(seconds: number): void {
        if (!this.duration) return;
        const t = ReframeGeometry.clamp(seconds, 0, this.duration);
        this._video.currentTime = t;
        this.currentTime = t;
        this._needsDraw = true;
    }

    public seekFromRuler(event: PointerEvent): void {
        const ruler = this.$refs.ruler;
        if (!ruler) return;
        const rect = ruler.getBoundingClientRect();
        this.seek(((event.clientX - rect.left) / rect.width) * this.duration);
    }

    public timeLabel(): string {
        const fmt = (s: number): string =>
            `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

        return `${fmt(this.currentTime)} / ${fmt(this.duration)}`;
    }

    public addKeyframeAtCurrentTime(): void {
        const index = this.ensureKeyframeAtCurrentTime();
        if (index === null) return;
        this.selectedKf = index;
        this.dirty = true;
        this._needsDraw = true;
    }

    public selectKeyframe(index: number): void {
        this.selectedKf = index;
        const keyframe = this.keyframes[index];
        if (keyframe) this.seek(keyframe.t);
    }

    public deleteSelectedKeyframe(): void {
        if (this.selectedKf === null || this.keyframes.length <= 1) return;
        this.keyframes.splice(this.selectedKf, 1);
        this.selectedKf = null;
        this.dirty = true;
        this._needsDraw = true;
    }

    public setMode(mode: string): void {
        if (mode === this.mode || !ReframeModes.exists(mode)) return;
        const discards = this.dirty || this.keyframes.length > 1;
        if (discards && !confirm('Trocar o modo descarta os keyframes atuais. Continuar?')) return;

        this.mode = mode;
        this.activeRegion = 0;
        this.keyframes = this.duration ? [this.defaultKeyframe()] : [];
        this.selectedKf = this.keyframes.length ? 0 : null;
        this.dirty = true;
        this._boxes = null;
        this._needsDraw = true;
    }

    public setActiveRegion(index: number): void {
        this.activeRegion = index;
    }

    public modeOptions(): { value: string; label: string }[] {
        return ReframeModes.options();
    }

    public modeSlots(): Slot[] {
        return ReframeModes.get(this.mode).slots;
    }

    public regionTabs(): { i: number; label: string }[] {
        const labels = ReframeModes.REGION_LABELS[ReframeModes.get(this.mode).slots.length] ?? [];

        return labels.map((label, i) => ({ i, label }));
    }

    public isContain(): boolean {
        return ReframeModes.get(this.mode).fit === 'contain';
    }

    public markDirty(): void {
        this.dirty = true;
        this._needsDraw = true;
    }

    public onPointerDown(event: PointerEvent, type: DragHandle): void {
        if (!this.duration || this._drag) return;
        event.preventDefault();
        (event.target as Element).setPointerCapture(event.pointerId);

        const kfIndex = this.ensureKeyframeAtCurrentTime();
        if (kfIndex === null) return;
        this.selectedKf = kfIndex;

        const region = this.keyframes[kfIndex]?.regions[this.activeRegion];
        if (region === undefined) return;

        this._drag = {
            type,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            start: { ...region },
            kfIndex,
        };
        this._dragLive = { ...region };
    }

    public onPointerMove(event: PointerEvent): void {
        if (!this._drag || event.pointerId !== this._drag.pointerId) return;
        const overlay = this.$refs.overlay;
        if (!overlay) return;

        const rect = overlay.getBoundingClientRect();
        const dx = (event.clientX - this._drag.startX) / rect.width;
        const dy = (event.clientY - this._drag.startY) / rect.height;
        const start = this._drag.start;

        this._dragLive =
            this._drag.type === 'move'
                ? {
                      ...start,
                      x: ReframeGeometry.clamp(start.x + dx, 0, 1 - start.w),
                      y: ReframeGeometry.clamp(start.y + dy, 0, 1 - start.h),
                  }
                : ReframeGeometry.resize(
                      start,
                      this._drag.type,
                      dx,
                      dy,
                      ReframeGeometry.lockAspect(
                          this.mode,
                          this.activeRegion,
                          this._video.videoWidth,
                          this._video.videoHeight,
                      ),
                      this._video.videoWidth,
                      this._video.videoHeight,
                  );
        this._needsDraw = true;
    }

    public onPointerUp(event: PointerEvent): void {
        if (!this._drag || event.pointerId !== this._drag.pointerId) return;
        const { kfIndex } = this._drag;
        const committed = this._dragLive;
        this._drag = null;
        this._dragLive = null;

        const keyframe = this.keyframes[kfIndex];
        if (!keyframe || committed === null) return;

        keyframe.regions[this.activeRegion] = {
            x: ReframeGeometry.round4(committed.x),
            y: ReframeGeometry.round4(committed.y),
            w: ReframeGeometry.round4(committed.w),
            h: ReframeGeometry.round4(committed.h),
        };
        this.dirty = true;
        this._needsDraw = true;
    }

    public async save(): Promise<void> {
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
                this.dirty = false;
            }
        } catch (error) {
            ClientLogger.send('error', `Falha ao salvar o reframe: ${String(error)}`, {
                editId: this.editId,
            });
            this.$dispatch('toast', {
                message: 'Não foi possível salvar. Tente de novo.',
                variant: 'error',
            });
        } finally {
            this.saving = false;
        }
    }

    public async recoverVideoUrl(): Promise<void> {
        try {
            const url = await this.$wire.refreshUrl();
            if (!url) return;

            const t = this._video.currentTime;
            const wasPlaying = !this._video.paused;
            this.videoUrl = url;
            this._video.src = url;
            this._video.addEventListener(
                'loadedmetadata',
                () => {
                    this._video.currentTime = t;
                    if (wasPlaying) this._video.play().catch(() => undefined);
                },
                { once: true },
            );
        } catch {
            return;
        }
    }

    public regionsAt(t: number): Region[] | null {
        return ReframeGeometry.regionsAt(this.keyframes, t);
    }

    private handleMetadata(): void {
        this.duration = this._video.duration;
        const stage = this.$refs.stage;

        if (stage) {
            stage.style.aspectRatio = `${this._video.videoWidth} / ${this._video.videoHeight}`;
        }

        if (!this.keyframes.length) {
            this.keyframes = [this.defaultKeyframe()];
            this.selectedKf = 0;
        }

        this._needsDraw = true;
    }

    private tick(): void {
        this._raf = requestAnimationFrame(() => this.tick());
        if (document.hidden || this._video.readyState < 2) return;

        const t = this._video.currentTime;
        if (!this._needsDraw && t === this._lastDrawnT && !this._drag) return;

        this._lastDrawnT = t;
        this._needsDraw = false;

        const regions = this.liveRegions(t);
        if (regions === null) return;

        this._renderer.draw(this.mode, regions, this.settings);
        this.syncOverlay(t, regions);
    }

    private syncOverlay(t: number, regions: Region[]): void {
        const overlay = this.$refs.overlay;
        if (!overlay) return;

        if (!this._boxes || this._boxes.length !== regions.length) {
            this._boxes = [...overlay.querySelectorAll<HTMLElement>('[data-region-box]')];
        }

        if (this._boxes.length !== regions.length) return;

        this._renderer.syncBoxes(this._boxes, regions);

        const playhead = this.$refs.playhead;

        if (playhead && this.duration) {
            playhead.style.left = `${(t / this.duration) * 100}%`;
        }
    }

    private liveRegions(t: number): Region[] | null {
        const regions = this.regionsAt(t);
        if (regions === null) return null;
        if (this._dragLive === null) return regions;

        const live = regions.map((r) => ({ ...r }));
        live[this.activeRegion] = this._dragLive;

        return live;
    }

    private ensureKeyframeAtCurrentTime(): number | null {
        const t = ReframeGeometry.round3(this._video.currentTime);
        const existing = this.keyframes.findIndex(
            (kf) => Math.abs(kf.t - t) < ReframeEditor.T_EPSILON,
        );
        if (existing !== -1) return existing;

        if (this.keyframes.length >= ReframeEditor.MAX_KEYFRAMES) {
            this.$dispatch('toast', {
                message: `Limite de ${ReframeEditor.MAX_KEYFRAMES} keyframes atingido.`,
                variant: 'warning',
            });

            return null;
        }

        const source = this.regionsAt(t);
        if (source === null) return null;

        const keyframe: Keyframe = { t, regions: source.map((r) => ({ ...r })) };
        const index = this.keyframes.findIndex((kf) => kf.t > t);

        if (index === -1) {
            this.keyframes.push(keyframe);

            return this.keyframes.length - 1;
        }

        this.keyframes.splice(index, 0, keyframe);

        return index;
    }

    private defaultKeyframe(): Keyframe {
        return {
            t: 0,
            regions: ReframeModes.get(this.mode).slots.map((slot) =>
                ReframeGeometry.defaultRegion(
                    this.mode,
                    slot,
                    this._video.videoWidth,
                    this._video.videoHeight,
                ),
            ),
        };
    }
}
