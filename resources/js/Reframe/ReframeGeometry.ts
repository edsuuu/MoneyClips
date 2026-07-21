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

    public static defaultRegion(mode: string, slot: Slot, vw: number, vh: number): Region {
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

        return {
            x: ReframeGeometry.round4((1 - w) / 2),
            y: ReframeGeometry.round4((1 - h) / 2),
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
}
