import type { StoryboardConfig } from '../Player/VideoPlayer';
import { Timecode } from '../Support/Timecode.ts';

export interface TrimEditorConfig {
    storyboard: StoryboardConfig;
    duration: number;
}

interface CutWire {
    addCut: (start: number, end: number) => Promise<void>;
}

export class TrimEditor {
    // Espelha VideoCut::MAX_DURATION_SECONDS — o addCut do Livewire revalida.
    private static readonly MAX_CUT_SECONDS = 180;

    // ponytail: contagem fixa de tiles; densidade por largura do container se precisar.
    private static readonly TILE_COUNT = 16;

    public a = 0;

    public b: number;

    public dragging: 'a' | 'b' | 'window' | null = null;

    public saving = false;

    public syncPlayer = true;

    public $refs!: Record<string, HTMLElement | undefined>;

    public $wire!: CutWire;

    public $dispatch!: (event: string, detail?: unknown) => void;

    private readonly storyboard: StoryboardConfig;

    private readonly duration: number;

    private windowAnchor: { time: number; a: number; width: number } | null = null;

    public constructor(config: TrimEditorConfig) {
        this.storyboard = config.storyboard;
        this.duration = config.duration;
        this.b = Math.min(config.duration, TrimEditor.MAX_CUT_SECONDS);
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

    public startDrag(which: 'a' | 'b' | 'window', event: PointerEvent): void {
        this.dragging = which;
        (event.target as HTMLElement).setPointerCapture?.(event.pointerId);

        // Agarra a janela pelo meio: guarda o ponto do clique e a largura pra
        // arrastar início+fim juntos sem saltar pro cursor.
        if (which === 'window') {
            this.windowAnchor = {
                time: this.fraction(event) * this.duration,
                a: this.a,
                width: this.b - this.a,
            };

            return;
        }

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
            this.windowAnchor = null;

            return;
        }

        if (this.dragging === 'window') {
            this.moveWindow(event);

            return;
        }

        const time = this.fraction(event) * this.duration;

        if (this.dragging === 'a') {
            const previous = this.a;
            this.setStart(time);

            if (this.syncPlayer && this.a !== previous) {
                this.$dispatch('trim-scrub', { time: this.a });
            }
        } else {
            const previous = this.b;
            this.setEnd(time);

            if (this.syncPlayer && this.b !== previous) {
                this.$dispatch('trim-scrub', { time: this.b });
            }
        }
    }

    public endDrag(): void {
        this.dragging = null;
        this.windowAnchor = null;
    }

    private moveWindow(event: PointerEvent): void {
        const anchor = this.windowAnchor;

        if (anchor === null) {
            return;
        }

        const delta = this.fraction(event) * this.duration - anchor.time;
        const a = Math.min(Math.max(0, Math.round(anchor.a + delta)), this.duration - anchor.width);

        if (a === this.a) {
            return;
        }

        this.a = a;
        this.b = a + anchor.width;

        if (this.syncPlayer) {
            this.$dispatch('trim-scrub', { time: this.a });
        }
    }

    public seekFromClick(event: MouseEvent): void {
        this.$dispatch('trim-seek', { time: this.fraction(event) * this.duration });
    }

    public sanitizeTime(raw: string): string {
        return raw.replace(/[^0-9:]/g, '');
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

    public step(which: 'a' | 'b', dir: number): void {
        if (which === 'a') {
            const previous = this.a;
            this.setStart(this.a + dir);

            if (this.syncPlayer && this.a !== previous) {
                this.$dispatch('trim-scrub', { time: this.a });
            }
        } else {
            const previous = this.b;
            this.setEnd(this.b + dir);

            if (this.syncPlayer && this.b !== previous) {
                this.$dispatch('trim-scrub', { time: this.b });
            }
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
        return Timecode.format(seconds);
    }

    // Pontas independentes: cada handle só mexe no seu extremo, travado no corte
    // máximo (3 min) e sem cruzar o outro. Reposicionar a janela é no arraste do
    // meio (moveWindow), que preserva a duração.
    private setStart(time: number): void {
        const floor = Math.max(0, this.b - TrimEditor.MAX_CUT_SECONDS);

        this.a = Math.min(Math.max(floor, Math.round(time)), Math.max(0, this.b - 1));
    }

    private setEnd(time: number): void {
        const ceiling = Math.min(this.duration, this.a + TrimEditor.MAX_CUT_SECONDS);

        this.b = Math.max(Math.min(ceiling, Math.round(time)), Math.min(this.duration, this.a + 1));
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
