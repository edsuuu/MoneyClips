import { ReframeModes } from './ReframeModes';
import type { Region, ReframeSettings } from './ReframeTypes';

export class ReframeRenderer {
    public constructor(
        private readonly ctx: CanvasRenderingContext2D,
        private readonly video: HTMLVideoElement,
    ) {}

    public draw(mode: string, regions: Region[], settings: ReframeSettings): void {
        const { slots, fit } = ReframeModes.get(mode);
        const vw = this.video.videoWidth;
        const vh = this.video.videoHeight;

        if (fit === 'contain') {
            this.ctx.fillStyle = settings.background;
            this.ctx.fillRect(0, 0, ReframeModes.OUT_W, ReframeModes.OUT_H);
        }

        slots.forEach((slot, i) => {
            const r = regions[i];

            if (r === undefined) {
                return;
            }

            const sx = r.x * vw;
            const sy = r.y * vh;
            const sw = r.w * vw;
            const sh = r.h * vh;

            if (fit === 'contain') {
                const scale = Math.min(slot.w / sw, slot.h / sh);
                const dw = sw * scale;
                const dh = sh * scale;
                this.ctx.drawImage(
                    this.video,
                    sx,
                    sy,
                    sw,
                    sh,
                    slot.x + (slot.w - dw) / 2,
                    slot.y + (slot.h - dh) / 2,
                    dw,
                    dh,
                );

                return;
            }

            this.ctx.drawImage(this.video, sx, sy, sw, sh, slot.x, slot.y, slot.w, slot.h);
        });
    }

    public syncBoxes(boxes: HTMLElement[], regions: Region[]): void {
        boxes.forEach((el, i) => {
            const r = regions[i];

            if (r === undefined) {
                return;
            }

            el.style.left = `${r.x * 100}%`;
            el.style.top = `${r.y * 100}%`;
            el.style.width = `${r.w * 100}%`;
            el.style.height = `${r.h * 100}%`;
        });
    }
}
