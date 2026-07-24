import type { StoryboardConfig } from '../Player/VideoPlayer';

export interface TrimEditorConfig {
    storyboard: StoryboardConfig;
    duration: number;
}

interface CutWire {
    addCut: (start: number, end: number) => Promise<void>;
}

export class TrimEditor {
    // ponytail: contagem fixa de tiles; densidade por largura do container se precisar.
    private static readonly TILE_COUNT = 16;

    public a = 0;

    public b: number;

    public dragging: 'a' | 'b' | null = null;

    public saving = false;

    public $refs!: Record<string, HTMLElement | undefined>;

    public $wire!: CutWire;

    public $dispatch!: (event: string, detail?: unknown) => void;

    private readonly storyboard: StoryboardConfig;

    private readonly duration: number;

    public constructor(config: TrimEditorConfig) {
        this.storyboard = config.storyboard;
        this.duration = config.duration;
        this.b = config.duration;
    }

    public get tiles(): string[] {
        const { url, cols, rows, interval, tileWidth, tileHeight } = this.storyboard;
        const total = Math.max(1, cols * rows);
        const step = interval > 0 ? interval : this.duration / total;

        return Array.from({ length: TrimEditor.TILE_COUNT }, (_, k) => {
            const time = ((k + 0.5) / TrimEditor.TILE_COUNT) * this.duration;
            const index = Math.max(0, Math.min(total - 1, Math.floor(time / step)));
            const col = index % cols;
            const row = Math.floor(index / cols);
            const x = cols > 1 ? (col / (cols - 1)) * 100 : 0;
            const y = rows > 1 ? (row / (rows - 1)) * 100 : 0;

            return (
                `aspect-ratio:${String(tileWidth)}/${String(tileHeight)};` +
                `background-image:url('${url}');` +
                `background-size:${String(cols * 100)}% ${String(rows * 100)}%;` +
                `background-position:${String(x)}% ${String(y)}%;`
            );
        });
    }

    public startDrag(which: 'a' | 'b', event: PointerEvent): void {
        this.dragging = which;
        (event.target as HTMLElement).setPointerCapture?.(event.pointerId);
        this.onDrag(event);
    }

    public onDrag(event: PointerEvent): void {
        if (this.dragging === null) {
            return;
        }

        // Botão solto = drag acabou: mata estado preso caso o pointerup se perca
        // (pointer capture retargeta eventos e o pointermove chega de qualquer lugar).
        if (event.buttons === 0) {
            this.dragging = null;

            return;
        }

        const time = this.fraction(event) * this.duration;

        if (this.dragging === 'a') {
            this.setStart(time);
        } else {
            this.setEnd(time);
        }
    }

    public endDrag(): void {
        this.dragging = null;
    }

    public seekFromClick(event: MouseEvent): void {
        this.$dispatch('trim-seek', { time: this.fraction(event) * this.duration });
    }

    public applyStart(raw: string): void {
        const time = this.parse(raw);

        if (time !== null) {
            this.setStart(time);
        }
    }

    public applyEnd(raw: string): void {
        const time = this.parse(raw);

        if (time !== null) {
            this.setEnd(time);
        }
    }

    public async addCut(): Promise<void> {
        if (this.saving) {
            return;
        }

        this.saving = true;

        try {
            await this.$wire.addCut(this.a, this.b);
        } finally {
            this.saving = false;
        }
    }

    public get aPercent(): number {
        return this.duration > 0 ? (this.a / this.duration) * 100 : 0;
    }

    public get bPercent(): number {
        return this.duration > 0 ? (this.b / this.duration) * 100 : 0;
    }

    public get aInput(): string {
        return this.timecode(this.a);
    }

    public get bInput(): string {
        return this.timecode(this.b);
    }

    public get rangeLabel(): string {
        return this.timecode(this.b - this.a);
    }

    public timecode(seconds: number): string {
        const total = Math.max(0, Math.floor(seconds));
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        const mm = String(m).padStart(2, '0');
        const ss = String(s).padStart(2, '0');

        return h > 0 ? `${String(h)}:${mm}:${ss}` : `${mm}:${ss}`;
    }

    private setStart(time: number): void {
        this.a = Math.min(Math.max(0, Math.round(time)), Math.max(0, this.b - 1));
    }

    private setEnd(time: number): void {
        this.b = Math.max(
            Math.min(this.duration, Math.round(time)),
            Math.min(this.duration, this.a + 1),
        );
    }

    private parse(raw: string): number | null {
        const parts = raw.trim().split(':');

        if (parts.length > 3 || parts.some((part) => !/^\d+$/.test(part))) {
            return null;
        }

        return parts.reduce((total, part) => total * 60 + Number(part), 0);
    }

    private fraction(event: { clientX: number }): number {
        const strip = this.$refs.strip;

        if (!strip) {
            return 0;
        }

        const rect = strip.getBoundingClientRect();

        return Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
    }
}
