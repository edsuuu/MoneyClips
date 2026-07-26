import { ReframeModes } from './ReframeModes';
import type { DragHandle, Keyframe, Region, Slot } from './ReframeTypes';

export class ReframeGeometry {
    public static readonly MIN_SIZE = 0.05;

    public static clamp(value: number, min: number, max: number): number {
        return Math.min(Math.max(value, min), max);
    }

    public static round4(value: number): number {
        return Math.round(value * 10000) / 10000;
    }

    public static round3(value: number): number {
        return Math.round(value * 1000) / 1000;
    }

    public static regionsAt(keyframes: Keyframe[], t: number): Region[] | null {
        const first = keyframes[0];

        if (first === undefined) {
            return null;
        }

        if (t <= first.t) {
            return first.regions;
        }

        const last = keyframes[keyframes.length - 1];

        if (last === undefined || t >= last.t) {
            return last?.regions ?? null;
        }

        let i = 1;
        while ((keyframes[i]?.t ?? Infinity) < t) i++;

        const a = keyframes[i - 1];
        const b = keyframes[i];

        if (a === undefined || b === undefined) {
            return last.regions;
        }

        if (a.mode !== b.mode) {
            return a.regions;
        }

        const f = (t - a.t) / (b.t - a.t);

        return a.regions.map((ra, j) => {
            const rb = b.regions[j] ?? ra;

            return {
                x: ra.x + (rb.x - ra.x) * f,
                y: ra.y + (rb.y - ra.y) * f,
                w: ra.w + (rb.w - ra.w) * f,
                h: ra.h + (rb.h - ra.h) * f,
            };
        });
    }

    public static modeAt(keyframes: Keyframe[], t: number): string | null {
        const first = keyframes[0];

        if (first === undefined) {
            return null;
        }

        let active = first;
        for (const keyframe of keyframes) {
            if (keyframe.t > t) break;
            active = keyframe;
        }

        return active.mode;
    }

    public static defaultRegion(
        mode: string,
        slot: Slot,
        vw: number,
        vh: number,
        index = 0,
        total = 1,
    ): Region {
        let w = 1;
        let h = 1;

        if (ReframeModes.get(mode).lock === 'slot') {
            const aspect = slot.w / slot.h;

            if (vw / vh >= aspect) {
                w = (aspect * vh) / vw;
            } else {
                h = vw / (aspect * vh);
            }
        }

        let x = (1 - w) / 2;
        let y = (1 - h) / 2;

        if (total > 1) {
            const spread = index / (total - 1);

            if (w < 1) {
                x = (1 - w) * spread;
            } else if (h < 1) {
                y = (1 - h) * spread;
            }
        }

        return {
            x: ReframeGeometry.round4(x),
            y: ReframeGeometry.round4(y),
            w: ReframeGeometry.round4(w),
            h: ReframeGeometry.round4(h),
        };
    }

    public static lockAspect(
        mode: string,
        activeRegion: number,
        vw: number,
        vh: number,
    ): number | null {
        const definition = ReframeModes.get(mode);

        if (definition.lock === 'free') {
            return null;
        }

        if (definition.lock === 'source') {
            return vw / vh;
        }

        const slot = definition.slots[activeRegion];

        return slot === undefined ? null : slot.w / slot.h;
    }

    public static resize(
        start: Region,
        handle: DragHandle,
        dx: number,
        dy: number,
        aspect: number | null,
        vw: number,
        vh: number,
    ): Region {
        if (handle === 'adjust') {
            return ReframeGeometry.scaleFromCenter(start, dx, aspect, vw, vh);
        }

        if (handle === 'n' || handle === 's' || handle === 'e' || handle === 'w') {
            return ReframeGeometry.resizeEdge(start, handle, dx, dy, aspect, vw, vh);
        }

        const west = handle.includes('w');
        const north = handle.includes('n');

        const anchorX = west ? start.x + start.w : start.x;
        const anchorY = north ? start.y + start.h : start.y;
        const cornerX = ReframeGeometry.clamp((west ? start.x : start.x + start.w) + dx, 0, 1);
        const cornerY = ReframeGeometry.clamp((north ? start.y : start.y + start.h) + dy, 0, 1);

        let w = Math.abs(cornerX - anchorX);
        let h = Math.abs(cornerY - anchorY);
        const maxW = west ? anchorX : 1 - anchorX;
        const maxH = north ? anchorY : 1 - anchorY;

        if (aspect !== null) {
            const wFromMaxH = (maxH * aspect * vh) / vw;
            w = ReframeGeometry.clamp(
                Math.max(w, ReframeGeometry.MIN_SIZE),
                ReframeGeometry.MIN_SIZE,
                Math.min(maxW, wFromMaxH),
            );
            h = (w * vw) / (aspect * vh);
        } else {
            w = ReframeGeometry.clamp(
                Math.max(w, ReframeGeometry.MIN_SIZE),
                ReframeGeometry.MIN_SIZE,
                maxW,
            );
            h = ReframeGeometry.clamp(
                Math.max(h, ReframeGeometry.MIN_SIZE),
                ReframeGeometry.MIN_SIZE,
                maxH,
            );
        }

        return {
            x: west ? anchorX - w : anchorX,
            y: north ? anchorY - h : anchorY,
            w,
            h,
        };
    }

    private static scaleFromCenter(
        start: Region,
        dx: number,
        aspect: number | null,
        vw: number,
        vh: number,
    ): Region {
        const centerX = start.x + start.w / 2;
        const centerY = start.y + start.h / 2;

        const maxW = Math.min(centerX, 1 - centerX) * 2;
        const maxH = Math.min(centerY, 1 - centerY) * 2;

        let w = ReframeGeometry.clamp(start.w + dx, ReframeGeometry.MIN_SIZE, maxW);
        let h: number;

        if (aspect !== null) {
            h = (w * vw) / (aspect * vh);

            if (h > maxH) {
                h = maxH;
                w = (h * aspect * vh) / vw;
            }
        } else {
            h = ReframeGeometry.clamp(start.h * (w / start.w), ReframeGeometry.MIN_SIZE, maxH);
        }

        return {
            x: centerX - w / 2,
            y: centerY - h / 2,
            w,
            h,
        };
    }

    private static resizeEdge(
        start: Region,
        handle: 'n' | 's' | 'e' | 'w',
        dx: number,
        dy: number,
        aspect: number | null,
        vw: number,
        vh: number,
    ): Region {
        const horizontal = handle === 'e' || handle === 'w';
        const grow = handle === 'e' || handle === 's' ? 1 : -1;
        const delta = horizontal ? dx * grow : dy * grow;

        const anchorX = handle === 'w' ? start.x + start.w : start.x;
        const anchorY = handle === 'n' ? start.y + start.h : start.y;
        const maxW = handle === 'w' ? anchorX : 1 - anchorX;
        const maxH = handle === 'n' ? anchorY : 1 - anchorY;

        let w = start.w;
        let h = start.h;

        if (horizontal) {
            w = ReframeGeometry.clamp(start.w + delta, ReframeGeometry.MIN_SIZE, maxW);
            if (aspect !== null) h = (w * vw) / (aspect * vh);
        } else {
            h = ReframeGeometry.clamp(start.h + delta, ReframeGeometry.MIN_SIZE, maxH);
            if (aspect !== null) w = (h * aspect * vh) / vw;
        }

        if (aspect !== null) {
            const centerX = horizontal ? 0 : start.x + start.w / 2;
            const centerY = horizontal ? start.y + start.h / 2 : 0;

            if (horizontal) {
                const maxSecondary = Math.min(centerY, 1 - centerY) * 2;
                if (h > maxSecondary) {
                    h = maxSecondary;
                    w = (h * aspect * vh) / vw;
                }
            } else {
                const maxSecondary = Math.min(centerX, 1 - centerX) * 2;
                if (w > maxSecondary) {
                    w = maxSecondary;
                    h = (w * vw) / (aspect * vh);
                }
            }
        }

        const x = horizontal
            ? handle === 'w'
                ? anchorX - w
                : anchorX
            : ReframeGeometry.clamp(start.x + start.w / 2 - w / 2, 0, 1 - w);
        const y = horizontal
            ? ReframeGeometry.clamp(start.y + start.h / 2 - h / 2, 0, 1 - h)
            : handle === 'n'
              ? anchorY - h
              : anchorY;

        return { x, y, w, h };
    }
}
