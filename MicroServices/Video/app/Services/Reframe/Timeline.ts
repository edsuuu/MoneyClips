import type {
    ReframeKeyframe,
    ReframeRegion,
    ReframeSfx,
} from '@/Services/Reframe/ReframeFilterBuilder';

export type TimeRange = [number, number];

const MIN_KEEP_SECONDS = 0.1;
const MIN_WINDOW_SECONDS = 0.05;
const CUT_TOLERANCE_SECONDS = 1;
const RHYTHM_ZOOM = 1.3;
const MAX_ZOOM = 3;
const MIN_SHOT_SECONDS = 0.4;
const SAME_REGION = 0.0001;
const FIT_SLACK = 0.001;

/**
 * Porte da Timeline do render.py: os trechos `keep` do clip, concatenados,
 * viram o tempo de saída. Tudo que chega em tempo do clip (keyframes,
 * captions, overlays, sfx) passa por aqui; o que cai num trecho removido sai
 * ou é aparado.
 */
export class Timeline {
    public readonly keep: TimeRange[];
    public readonly duration: number;
    private readonly offsets: number[];

    public constructor(keep: TimeRange[]) {
        this.keep = [...keep].sort((a, b) => a[0] - b[0]);
        this.offsets = [0];

        for (const [start, end] of this.keep) {
            this.offsets.push(this.offsets.at(-1)! + end - start);
        }

        this.duration = this.offsets.at(-1)!;
    }

    /**
     * keep = [0, duração] − removidos. As bordas vão pro grid de frames: assim
     * o trim do vídeo e o atrim do áudio de cada trecho têm a mesma duração e
     * a emenda não acumula dessincronia.
     */
    public static fromRemoved(duration: number, removed: TimeRange[], fps: number): Timeline {
        const keep: TimeRange[] = [];
        let cursor = 0;

        for (const [start, end] of [...removed].sort((a, b) => a[0] - b[0])) {
            if (end > duration + CUT_TOLERANCE_SECONDS) {
                throw new Error(
                    `cuts fora do clip: [${String(start)}, ${String(end)}] passa da duração ${String(duration)}s.`,
                );
            }

            if (start > cursor) {
                keep.push([cursor, Math.min(start, duration)]);
            }

            cursor = Math.max(cursor, end);
        }

        if (cursor < duration) {
            keep.push([cursor, duration]);
        }

        const frames: TimeRange[] = [];

        for (const [start, end] of keep) {
            const range: TimeRange = [Math.round(start * fps) / fps, Math.round(end * fps) / fps];

            if (range[1] - range[0] >= MIN_KEEP_SECONDS) {
                frames.push(range);
            }
        }

        if (frames.length === 0) {
            throw new Error('cuts e ar morto removem o clip inteiro.');
        }

        return new Timeline(frames);
    }

    public out(time: number): number {
        for (const [index, [start, end]] of this.keep.entries()) {
            if (time < start) {
                return this.offsets[index]!;
            }

            if (time <= end) {
                return this.offsets[index]! + time - start;
            }
        }

        return this.duration;
    }

    public span(start: number, end: number): TimeRange {
        return [this.out(start), this.out(end)];
    }

    /** Captions e overlays: a janela é aparada nas bordas e some se sobrar menos de 50ms. */
    public windows<T extends { t: TimeRange }>(items: T[]): T[] {
        const kept: T[] = [];

        for (const item of items) {
            const t = this.span(item.t[0], item.t[1]);

            if (t[1] - t[0] >= MIN_WINDOW_SECONDS) {
                kept.push({ ...item, t });
            }
        }

        return kept;
    }

    public sfx(sfx: ReframeSfx[]): ReframeSfx[] {
        const kept: ReframeSfx[] = [];

        for (const item of sfx) {
            if (this.keep.some(([start, end]) => item.t >= start && item.t < end)) {
                kept.push({ ...item, t: this.out(item.t) });
            }
        }

        return kept;
    }

    /**
     * Cada trecho leva o enquadramento das suas bordas (amostrado como o
     * /reframe interpola) e os keyframes de dentro. Na emenda, os dois
     * keyframes de mesmo t viram degrau. Emenda sem troca de enquadramento
     * ganha o jump cut escondido do guia (§8.4): alterna 1.0↔1.3, só se a
     * tomada anterior durou ≥ 0.4s.
     *
     * ponytail: o degrau só existe onde o enquadramento recebido não muda (as
     * emendas de ar morto: as dos cuts já chegam com o degrau do Laravel) e
     * vale até o Laravel mudar o enquadramento. Sem saber em que ponto do
     * ritmo o Laravel está, o degrau abre quando a região cabe ampliada e
     * fecha quando não cabe: nunca empilha 1.3×1.3, mas no plano aberto do
     * tracking (zoom-base > 1.3×) abre em vez de fechar. Upgrade: o ritmo
     * inteiro (legenda + emendas) num lugar só, em tempo de saída.
     */
    public keyframes(keyframes: ReframeKeyframe[]): ReframeKeyframe[] {
        const remapped: ReframeKeyframe[] = [];
        let previous: ReframeKeyframe | null = null;
        let zoomed = false;
        let lastChange = -Infinity;

        for (const [index, [start, end]] of this.keep.entries()) {
            const shot = [
                this.at(keyframes, start),
                ...keyframes.filter((keyframe) => keyframe.t > start && keyframe.t < end),
                this.at(keyframes, end),
            ];

            for (const [position, keyframe] of shot.entries()) {
                const t = this.offsets[index]! + keyframe.t - start;

                if (previous !== null && !this.sameFraming(previous, keyframe)) {
                    zoomed = false;
                    lastChange = t;
                } else if (position === 0 && index > 0 && t - lastChange >= MIN_SHOT_SECONDS) {
                    zoomed = !zoomed;
                    lastChange = t;
                }

                remapped.push({
                    ...keyframe,
                    t,
                    regions: zoomed
                        ? keyframe.regions.map((region) => this.zoom(region))
                        : keyframe.regions,
                });
                previous = keyframe;
            }
        }

        return remapped;
    }

    /** Mesma interpolação do ReframeFilterBuilder: linear no mesmo modo, degrau na troca. */
    private at(keyframes: ReframeKeyframe[], time: number): ReframeKeyframe {
        let previous = keyframes[0]!;

        for (const [index, keyframe] of keyframes.entries()) {
            if (keyframe.t <= time) {
                previous = keyframe;
                continue;
            }

            if (
                index === 0 ||
                keyframe.mode !== previous.mode ||
                keyframe.regions.length !== previous.regions.length
            ) {
                return { ...previous, t: time };
            }

            const ratio = (time - previous.t) / Math.max(keyframe.t - previous.t, 0.001);

            return {
                t: time,
                mode: previous.mode,
                regions: previous.regions.map((region, slot) => {
                    const target = keyframe.regions[slot]!;

                    return {
                        x: region.x + (target.x - region.x) * ratio,
                        y: region.y + (target.y - region.y) * ratio,
                        w: region.w + (target.w - region.w) * ratio,
                        h: region.h + (target.h - region.h) * ratio,
                    };
                }),
            };
        }

        return { ...previous, t: time };
    }

    private sameFraming(a: ReframeKeyframe, b: ReframeKeyframe): boolean {
        return (
            a.mode === b.mode &&
            a.regions.length === b.regions.length &&
            a.regions.every((region, slot) => {
                const other = b.regions[slot]!;

                return (
                    Math.abs(region.x - other.x) < SAME_REGION &&
                    Math.abs(region.y - other.y) < SAME_REGION &&
                    Math.abs(region.w - other.w) < SAME_REGION &&
                    Math.abs(region.h - other.h) < SAME_REGION
                );
            })
        );
    }

    /** Degrau no centro da região: abre 1.3× se cabe ampliada, senão fecha 1.3× (teto de 3×, região ≥ 1/3 da altura). */
    private zoom(region: ReframeRegion): ReframeRegion {
        const fits =
            region.w * RHYTHM_ZOOM <= 1 + FIT_SLACK && region.h * RHYTHM_ZOOM <= 1 + FIT_SLACK;
        const factor = fits ? 1 / RHYTHM_ZOOM : Math.min(RHYTHM_ZOOM, region.h * MAX_ZOOM);

        if (!fits && factor <= 1) {
            return region;
        }

        const w = Math.min(region.w / factor, 1);
        const h = Math.min(region.h / factor, 1);

        return {
            x: Math.min(Math.max(region.x + (region.w - w) / 2, 0), 1 - w),
            y: Math.min(Math.max(region.y + (region.h - h) / 2, 0), 1 - h),
            w,
            h,
        };
    }
}
