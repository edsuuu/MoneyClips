import { Filmstrip } from './Filmstrip';
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
import { Timecode } from '../Support/Timecode';

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

    public renderStatus: string | null = null;

    public generating = false;

    public trackingStatus: string | null = null;

    public tracking = false;

    public speed = 1;

    public timelineZoom = 1;

    public thumbs: string[] = [];

    public selectedKf: number | null = null;

    public activeRegion = 0;

    public $refs!: Record<string, HTMLElement | undefined>;

    public $wire!: {
        saveEdit: (payload: unknown) => Promise<number | null>;
        refreshUrl: () => Promise<string | null>;
        generateRender: () => Promise<string | null>;
        generateTracking: () => Promise<string | null>;
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

    private _undoStack: string[] = [];

    private _redoStack: string[] = [];

    private _kfDrag: { index: number; pointerId: number; moved: boolean } | null = null;

    private _timelineScrub: number | null = null;

    private _recovering = false;

    private _recoverAttempts = 0;

    public constructor(initial: ReframePayload) {
        this.editId = initial.editId;
        this.renderStatus = initial.renderStatus;
        this.trackingStatus = initial.trackingStatus;
        this.videoUrl = initial.videoUrl;
        this.mode = ReframeModes.exists(initial.mode) ? initial.mode : 'vertical';
        this.keyframes = initial.keyframes.map((keyframe) => {
            const mode = keyframe.mode ?? initial.mode;

            return {
                t: keyframe.t,
                mode: ReframeModes.exists(mode) ? mode : this.mode,
                regions: keyframe.regions,
            };
        });
        this.settings = {
            background: initial.settings.background,
            captions: initial.settings.captions ?? false,
            captionColor: initial.settings.captionColor ?? '#ffffff',
            captionCase: initial.settings.captionCase ?? 'sentence',
            speakerColors: initial.settings.speakerColors ?? {},
        };
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
        this._video.addEventListener('playing', () => {
            this._recoverAttempts = 0;
        });
        this._video.addEventListener('pause', () => {
            this.playing = false;
        });
        this._video.addEventListener('error', () => {
            const code = this._video.error?.code;
            const recoverable =
                code === MediaError.MEDIA_ERR_NETWORK ||
                code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED;

            if (recoverable && this._recoverAttempts < 3) {
                this._recoverAttempts += 1;
                void this.recoverVideoUrl();
            } else if (code !== undefined) {
                ClientLogger.send('warning', `Vídeo do editor falhou (código ${String(code)}).`);
            }
        });

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

        if (this._video.error !== null) {
            void this.recoverVideoUrl(true);

            return;
        }

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

    public timeLabel(): string {
        const seconds = Math.floor(this.currentTime % 60);
        const tenths = Math.floor((this.currentTime * 10) % 10);
        const current = `${String(Math.floor(this.currentTime / 60))}:${String(seconds).padStart(2, '0')}.${String(tenths)}`;

        return `${current} / ${ReframeEditor.fmtTime(this.duration)}`;
    }

    public zoomIn(): void {
        this.timelineZoom = Math.min(4, this.timelineZoom + 1);
    }

    public zoomOut(): void {
        this.timelineZoom = Math.max(1, this.timelineZoom - 1);
    }

    public timelineTicks(): { pct: number; label: string | null }[] {
        if (!this.duration || !Number.isFinite(this.duration)) return [];

        const minorStep = 2.5;
        const ticks: { pct: number; label: string | null }[] = [];
        const count = Math.min(2000, Math.floor(this.duration / minorStep) + 1);

        for (let index = 0; index < count; index += 1) {
            const t = index * minorStep;
            ticks.push({
                pct: (t / this.duration) * 100,
                label: index % 4 === 0 ? ReframeEditor.fmtTime(t) : null,
            });
        }

        return ticks;
    }

    public startKeyframeDrag(index: number, event: PointerEvent): void {
        if (!this.duration) return;
        (event.target as Element).setPointerCapture(event.pointerId);
        this.selectedKf = index;
        this._kfDrag = { index, pointerId: event.pointerId, moved: false };
    }

    public onTimelinePointerDown(event: PointerEvent): void {
        if (!this.duration || this._kfDrag) return;
        (event.currentTarget as Element).setPointerCapture(event.pointerId);
        this._timelineScrub = event.pointerId;
        const t = this.timelineTime(event);
        if (t !== null) this.seek(t);
    }

    public onTimelinePointerMove(event: PointerEvent): void {
        if (this._kfDrag && event.pointerId === this._kfDrag.pointerId) {
            const t = this.timelineTime(event);
            const keyframe = this.keyframes[this._kfDrag.index];
            if (t === null || !keyframe) return;

            if (!this._kfDrag.moved) {
                this.snapshot();
                this._kfDrag.moved = true;
            }

            const previous = this.keyframes[this._kfDrag.index - 1]?.t;
            const next = this.keyframes[this._kfDrag.index + 1]?.t ?? this.duration;
            const min = previous === undefined ? 0 : previous + 0.1;
            keyframe.t = ReframeGeometry.round3(
                ReframeGeometry.clamp(t, min, Math.max(min, next - 0.1)),
            );
            this.dirty = true;
            this._needsDraw = true;

            return;
        }

        if (this._timelineScrub === event.pointerId) {
            const t = this.timelineTime(event);
            if (t !== null) this.seek(t);
        }
    }

    public onTimelinePointerUp(event: PointerEvent): void {
        if (this._kfDrag && event.pointerId === this._kfDrag.pointerId) {
            this._kfDrag = null;

            return;
        }

        if (this._timelineScrub === event.pointerId) {
            this._timelineScrub = null;
        }
    }

    private timelineTime(event: PointerEvent): number | null {
        const timeline = this.$refs.timeline;
        if (!timeline) return null;

        const rect = timeline.getBoundingClientRect();

        return (
            ReframeGeometry.clamp((event.clientX - rect.left) / rect.width, 0, 1) * this.duration
        );
    }

    public addKeyframeAtCurrentTime(): void {
        const before = this.keyframes.length;
        const index = this.snapshotAndEnsureKeyframe();
        if (index === null) return;
        if (this.keyframes.length === before) this._undoStack.pop();
        this.selectedKf = index;
        this.dirty = true;
        this._needsDraw = true;
    }

    public stepFrame(direction: number): void {
        this.seek(this._video.currentTime + direction / 30);
    }

    public cycleSpeed(): void {
        const speeds = [1, 1.5, 2, 0.5];
        this.speed = speeds[(speeds.indexOf(this.speed) + 1) % speeds.length] ?? 1;
        this._video.playbackRate = this.speed;
    }

    public canUndo(): boolean {
        return this._undoStack.length > 0;
    }

    public canRedo(): boolean {
        return this._redoStack.length > 0;
    }

    public undo(): void {
        const previous = this._undoStack.pop();
        if (previous === undefined) return;
        this._redoStack.push(JSON.stringify(this.keyframes));
        this.restoreKeyframes(previous);
    }

    public redo(): void {
        const next = this._redoStack.pop();
        if (next === undefined) return;
        this._undoStack.push(JSON.stringify(this.keyframes));
        this.restoreKeyframes(next);
    }

    public selectKeyframe(index: number): void {
        this.selectedKf = index;
        const keyframe = this.keyframes[index];
        if (keyframe) this.seek(keyframe.t);
    }

    public deleteKeyframeAt(index: number): void {
        if (this.keyframes[index] === undefined) return;
        this.snapshot();

        if (this.keyframes.length === 1) {
            this.mode = 'vertical';
            this.keyframes = [this.defaultKeyframe()];
            this.selectedKf = 0;
        } else {
            this.keyframes.splice(index, 1);
            this.selectedKf = null;
        }

        this.activeRegion = 0;
        this.dirty = true;
        this._boxes = null;
        this._needsDraw = true;
    }

    public setMode(mode: string): void {
        if (!ReframeModes.exists(mode) || !this.duration) return;

        const current =
            ReframeGeometry.modeAt(this.keyframes, this._video.currentTime) ?? this.mode;
        if (current === mode) return;

        const index = this.snapshotAndEnsureKeyframe();
        if (index === null) return;

        const keyframe = this.keyframes[index];
        if (!keyframe) return;

        keyframe.mode = mode;
        keyframe.regions = ReframeModes.get(mode).slots.map((slot, index, slots) =>
            ReframeGeometry.defaultRegion(
                mode,
                slot,
                this._video.videoWidth,
                this._video.videoHeight,
                index,
                slots.length,
            ),
        );

        this.mode = mode;
        this.activeRegion = 0;
        this.selectedKf = index;
        this.dirty = true;
        this._boxes = null;
        this._needsDraw = true;
    }

    private static readonly REGION_COLORS = [
        {
            active: 'cursor-move border-emerald-400 bg-emerald-400/10',
            idle: 'cursor-pointer border-emerald-400/60',
            handle: 'bg-emerald-400',
            text: 'text-emerald-300',
        },
        {
            active: 'cursor-move border-violet-400 bg-violet-400/10',
            idle: 'cursor-pointer border-violet-400/60',
            handle: 'bg-violet-400',
            text: 'text-violet-300',
        },
        {
            active: 'cursor-move border-amber-400 bg-amber-400/10',
            idle: 'cursor-pointer border-amber-400/60',
            handle: 'bg-amber-400',
            text: 'text-amber-300',
        },
    ];

    public setActiveRegion(index: number): void {
        this.activeRegion = index;
    }

    public regionBoxClass(index: number): string {
        const color = ReframeEditor.REGION_COLORS[index % ReframeEditor.REGION_COLORS.length]!;

        return index === this.activeRegion ? `${color.active} z-10` : color.idle;
    }

    public regionHandleClass(index: number): string {
        return ReframeEditor.REGION_COLORS[index % ReframeEditor.REGION_COLORS.length]!.handle;
    }

    public regionLabel(index: number): string {
        const labels = ReframeModes.REGION_LABELS[ReframeModes.get(this.mode).slots.length] ?? [];

        return labels.length > 1 ? (labels[index] ?? '') : '9:16';
    }

    public captionCaseText(text: string): string {
        if (this.settings.captionCase === 'upper') return text.toUpperCase();
        if (this.settings.captionCase === 'lower') return text.toLowerCase();

        return text;
    }

    public regionTextClass(index: number): string {
        return ReframeEditor.REGION_COLORS[index % ReframeEditor.REGION_COLORS.length]!.text;
    }

    public regionScale(index: number): string {
        const regions = this.regionsAt(this.currentTime);
        const region = regions?.[index];
        const slot = ReframeModes.get(this.mode).slots[index];

        if (!region || !slot || !this._video.videoWidth) return '';

        const scale = slot.w / (region.w * this._video.videoWidth);

        return `${(Math.round(scale * 10) / 10).toFixed(1)}x`;
    }

    private static readonly SEGMENT_COLORS = [
        {
            dot: 'bg-green-500',
            strip: 'bg-green-500/25 border-green-500/50',
            tint: 'bg-green-500/15 border-green-500/30',
            text: 'text-green-500',
            ring: 'ring-[3px] ring-green-500/30',
        },
        {
            dot: 'bg-blue-500',
            strip: 'bg-blue-500/25 border-blue-500/50',
            tint: 'bg-blue-500/15 border-blue-500/30',
            text: 'text-blue-500',
            ring: 'ring-[3px] ring-blue-500/30',
        },
        {
            dot: 'bg-purple-500',
            strip: 'bg-purple-500/25 border-purple-500/50',
            tint: 'bg-purple-500/15 border-purple-500/30',
            text: 'text-purple-500',
            ring: 'ring-[3px] ring-purple-500/30',
        },
        {
            dot: 'bg-orange-500',
            strip: 'bg-orange-500/25 border-orange-500/50',
            tint: 'bg-orange-500/15 border-orange-500/30',
            text: 'text-orange-500',
            ring: 'ring-[3px] ring-orange-500/30',
        },
        {
            dot: 'bg-pink-500',
            strip: 'bg-pink-500/25 border-pink-500/50',
            tint: 'bg-pink-500/15 border-pink-500/30',
            text: 'text-pink-500',
            ring: 'ring-[3px] ring-pink-500/30',
        },
        {
            dot: 'bg-teal-500',
            strip: 'bg-teal-500/25 border-teal-500/50',
            tint: 'bg-teal-500/15 border-teal-500/30',
            text: 'text-teal-500',
            ring: 'ring-[3px] ring-teal-500/30',
        },
        {
            dot: 'bg-yellow-500',
            strip: 'bg-yellow-500/25 border-yellow-500/50',
            tint: 'bg-yellow-500/15 border-yellow-500/30',
            text: 'text-yellow-500',
            ring: 'ring-[3px] ring-yellow-500/30',
        },
    ];

    public cropSegments(): {
        i: number;
        startLabel: string;
        endLabel: string;
        startSec: number;
        endSec: number;
        modeLabel: string;
        leftPct: number;
        widthPct: number;
        color: { dot: string; strip: string; tint: string; text: string; ring: string };
    }[] {
        if (!this.duration) return [];

        return this.keyframes.map((keyframe, i) => {
            const end = this.keyframes[i + 1]?.t ?? this.duration;

            return {
                i,
                startLabel: ReframeEditor.fmtTime(keyframe.t),
                endLabel: ReframeEditor.fmtTime(end),
                startSec: keyframe.t,
                endSec: end,
                modeLabel: ReframeModes.get(keyframe.mode).label,
                leftPct: (keyframe.t / this.duration) * 100,
                widthPct: Math.max(2, ((end - keyframe.t) / this.duration) * 100),
                color: ReframeEditor.SEGMENT_COLORS[i % ReframeEditor.SEGMENT_COLORS.length]!,
            };
        });
    }

    public positionActiveRegion(event: MouseEvent): void {
        if (!this.duration || this._drag) return;
        const overlay = this.$refs.overlay;
        if (!overlay) return;

        const kfIndex = this.snapshotAndEnsureKeyframe();
        if (kfIndex === null) return;
        this.selectedKf = kfIndex;

        const keyframe = this.keyframes[kfIndex];
        const region = keyframe?.regions[this.activeRegion];
        if (!keyframe || region === undefined) return;

        const rect = overlay.getBoundingClientRect();
        const centerX = (event.clientX - rect.left) / rect.width;
        const centerY = (event.clientY - rect.top) / rect.height;

        keyframe.regions[this.activeRegion] = {
            x: ReframeGeometry.round4(
                ReframeGeometry.clamp(centerX - region.w / 2, 0, 1 - region.w),
            ),
            y: ReframeGeometry.round4(
                ReframeGeometry.clamp(centerY - region.h / 2, 0, 1 - region.h),
            ),
            w: region.w,
            h: region.h,
        };
        this.dirty = true;
        this._needsDraw = true;
    }

    private snapshotAndEnsureKeyframe(): number | null {
        this.snapshot();
        const index = this.ensureKeyframeAtCurrentTime();
        if (index === null) this._undoStack.pop();

        return index;
    }

    private snapshot(): void {
        this._undoStack.push(JSON.stringify(this.keyframes));
        if (this._undoStack.length > 50) this._undoStack.shift();
        this._redoStack = [];
    }

    private restoreKeyframes(serialized: string): void {
        this.keyframes = JSON.parse(serialized) as Keyframe[];
        this.selectedKf = null;
        this.activeRegion = 0;
        this.dirty = true;
        this._boxes = null;
        this._needsDraw = true;
    }

    private static fmtTime(seconds: number): string {
        return Timecode.format(seconds, false);
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

        const region = this.regionsAt(this._video.currentTime)?.[this.activeRegion];
        if (region === undefined) return;

        this._drag = {
            type,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            start: { ...region },
            kfIndex: -1,
        };
    }

    public onPointerMove(event: PointerEvent): void {
        if (!this._drag || event.pointerId !== this._drag.pointerId) return;
        const overlay = this.$refs.overlay;
        if (!overlay) return;

        if (this._drag.kfIndex === -1) {
            const distance = Math.hypot(
                event.clientX - this._drag.startX,
                event.clientY - this._drag.startY,
            );
            if (distance < 3) return;

            const kfIndex = this.snapshotAndEnsureKeyframe();
            if (kfIndex === null) {
                this._drag = null;

                return;
            }

            this.selectedKf = kfIndex;
            this._drag.kfIndex = kfIndex;
            this._dragLive = { ...this._drag.start };
        }

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

        if (kfIndex === -1) return;

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

    public async generate(): Promise<void> {
        if (this.generating || this.renderStatus === 'generating' || !this.duration) return;
        this.generating = true;

        try {
            if (this.dirty || this.editId === null) {
                await this.save();
                if (this.dirty || this.editId === null) return;
            }

            const status = await this.$wire.generateRender();
            if (status !== null) this.renderStatus = status;
        } catch (error) {
            ClientLogger.send('error', `Falha ao gerar o corte editado: ${String(error)}`, {
                editId: this.editId,
            });
            this.$dispatch('toast', {
                message: 'Não foi possível gerar o corte. Tente de novo.',
                variant: 'error',
            });
        } finally {
            this.generating = false;
        }
    }

    public async track(): Promise<void> {
        if (this.tracking || this.trackingStatus === 'processing' || !this.duration) return;

        if (this.keyframes.length > 1 && !window.confirm('O tracking substitui todos os keyframes atuais. Continuar?')) {
            return;
        }

        this.tracking = true;

        try {
            if (this.dirty || this.editId === null) {
                await this.save();
                if (this.dirty || this.editId === null) return;
            }

            const status = await this.$wire.generateTracking();
            if (status !== null) this.trackingStatus = status;
        } catch (error) {
            ClientLogger.send('error', `Falha ao gerar o tracking: ${String(error)}`, {
                editId: this.editId,
            });
            this.$dispatch('toast', {
                message: 'Não foi possível gerar o tracking. Tente de novo.',
                variant: 'error',
            });
        } finally {
            this.tracking = false;
        }
    }

    public speakerIds(): string[] {
        return Object.keys(this.settings.speakerColors ?? {}).sort(
            (first, second) => Number(first) - Number(second),
        );
    }

    public setSpeakerColor(speaker: string, color: string): void {
        this.settings.speakerColors = { ...(this.settings.speakerColors ?? {}), [speaker]: color };
        this.markDirty();
    }

    public async recoverVideoUrl(forcePlay = false): Promise<void> {
        if (this._recovering) return;
        this._recovering = true;

        try {
            const url = await this.$wire.refreshUrl();
            if (!url) return;

            const t = this._video.currentTime;
            const wasPlaying = forcePlay || !this._video.paused;
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
        } finally {
            this._recovering = false;
        }
    }

    public regionsAt(t: number): Region[] | null {
        return ReframeGeometry.regionsAt(this.keyframes, t);
    }

    private resolveDuration(): number {
        const raw = this._video.duration;
        if (Number.isFinite(raw) && raw > 0) return raw;

        const seekable = this._video.seekable;
        if (seekable.length > 0) {
            const end = seekable.end(seekable.length - 1);
            if (Number.isFinite(end) && end > 0) return end;
        }

        return 0;
    }

    private handleMetadata(): void {
        this.duration = this.resolveDuration();
        const stage = this.$refs.stage;

        if (stage) {
            stage.style.aspectRatio = `${this._video.videoWidth} / ${this._video.videoHeight}`;
        }

        if (!this.keyframes.length) {
            this.keyframes = [this.defaultKeyframe()];
            this.selectedKf = 0;
        }

        if (this.videoUrl && this.thumbs.length === 0) {
            const count = Math.min(60, Math.max(10, Math.ceil(this.duration)));
            void Filmstrip.generate(
                this.videoUrl,
                count,
                (index, url) => {
                    this.thumbs[index] = url;
                    this.thumbs = [...this.thumbs];
                },
                () => this._video.paused,
            ).catch(() => undefined);
        }

        this._needsDraw = true;
    }

    private tick(): void {
        this._raf = requestAnimationFrame(() => this.tick());
        if (document.hidden || this._video.readyState < 2) return;

        const t = this._video.currentTime;
        if (!this._needsDraw && t === this._lastDrawnT && !this._drag) return;

        this.currentTime = t;
        this._lastDrawnT = t;
        this._needsDraw = false;

        const activeMode = ReframeGeometry.modeAt(this.keyframes, t);
        if (activeMode !== null && activeMode !== this.mode) {
            this.mode = activeMode;
            this.activeRegion = 0;
            this._boxes = null;
        }

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

        const keyframe: Keyframe = {
            t,
            mode: ReframeGeometry.modeAt(this.keyframes, t) ?? this.mode,
            regions: source.map((r) => ({ ...r })),
        };
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
            mode: this.mode,
            regions: ReframeModes.get(this.mode).slots.map((slot, index, slots) =>
                ReframeGeometry.defaultRegion(
                    this.mode,
                    slot,
                    this._video.videoWidth,
                    this._video.videoHeight,
                    index,
                    slots.length,
                ),
            ),
        };
    }
}
