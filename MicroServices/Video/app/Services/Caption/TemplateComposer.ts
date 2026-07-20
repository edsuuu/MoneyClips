/**
 * Compõe a variante "template": moldura estática 1080x1920 (input 0) + o vídeo
 * escalado dentro da região calculada (input 1), com máscara de cantos e marca
 * d'água opcionais.
 *
 * `caption_position` decide ONDE a legenda é queimada:
 *   inside → dentro do vídeo escalado, antes do overlay
 *   below  → sobre a moldura inteira, depois do overlay (texto abaixo do vídeo)
 */

import { basename, dirname, join } from 'node:path';

import { Logger } from '@/Config/Logger';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';
import {
    CANVAS_HEIGHT,
    CANVAS_WIDTH,
    CORNER_RADIUS,
    StaticLayerRenderer,
} from '@/Services/Caption/StaticLayerRenderer';

const MARGIN_WIDE = 3;
const MARGIN_DEFAULT = 40;
const HEADER_BOTTOM = 560;
const WATERMARK_Y_RATIO = 0.8;

export interface VideoRegion {
    x: number;
    y: number;
    w: number;
    h: number;
    rounded: boolean;
}

export class TemplateComposer extends Logger {
    public constructor(
        private readonly ffmpeg: FfmpegRunner = new FfmpegRunner(),
        private readonly layers: StaticLayerRenderer = new StaticLayerRenderer(),
    ) {
        super();
    }

    /**
     * Fonte deitada ganha margem de 3px e cantos arredondados; retrato usa 40px
     * e cantos retos. A altura é forçada a par (yuv420p exige) e o topo nunca
     * invade o cabeçalho.
     */
    public computeRegion(sourceWidth: number, sourceHeight: number): VideoRegion {
        const wide = sourceWidth >= sourceHeight * 1.2;
        const margin = wide ? MARGIN_WIDE : MARGIN_DEFAULT;
        const w = CANVAS_WIDTH - 2 * margin;
        const h = 2 * Math.round((w * sourceHeight) / sourceWidth / 2);
        const y = Math.max(HEADER_BOTTOM, Math.floor((CANVAS_HEIGHT - h) / 2));

        return { x: margin, y, w, h, rounded: wide };
    }

    public async compose(
        source: string,
        assPath: string | null,
        staticPng: string,
        region: VideoRegion,
        out: string,
        captionPosition: string,
        watermarkText: string,
        durationSeconds: number,
    ): Promise<string> {
        const cwd = dirname(out);
        const inputs = ['-loop', '1', '-i', basename(staticPng), '-i', source];

        if (region.rounded) {
            const mask = join(cwd, `mask_${String(region.w)}x${String(region.h)}.png`);
            await this.layers.renderRoundedMask(region.w, region.h, CORNER_RADIUS, mask);
            inputs.push('-loop', '1', '-i', basename(mask));
        }

        let watermarkIndex: number | null = null;
        const text = watermarkText.trim();

        if (text !== '') {
            const watermark = join(cwd, 'watermark.png');
            await this.layers.renderWatermark(text, region.w, watermark);
            watermarkIndex = region.rounded ? 3 : 2;
            inputs.push('-loop', '1', '-i', basename(watermark));
        }

        const filter = this.buildFilter(region, assPath, captionPosition, watermarkIndex);
        const cap = durationSeconds > 0 ? ['-t', durationSeconds.toFixed(3)] : [];

        this.info(`[Caption] Compondo template (round=${String(region.rounded)}): ${out}`);

        await this.ffmpeg.runWithFallback(
            (encoderArgs) => [
                '-y',
                ...inputs,
                '-filter_complex',
                filter,
                '-map',
                '[out]',
                '-map',
                '1:a?',
                ...encoderArgs,
                '-c:a',
                'copy',
                ...cap,
                '-shortest',
                basename(out),
            ],
            cwd,
            'template',
        );

        return out;
    }

    private buildFilter(
        region: VideoRegion,
        assPath: string | null,
        position: string,
        watermarkIndex: number | null,
    ): string {
        const parts = [`[1:v]scale=${String(region.w)}:${String(region.h)}[vs]`];
        let current = '[vs]';

        if (watermarkIndex !== null) {
            parts.push(
                `${current}[${String(watermarkIndex)}:v]overlay=(W-w)/2:H*${String(WATERMARK_Y_RATIO)}-h/2[vwm]`,
            );
            current = '[vwm]';
        }

        if (region.rounded) {
            parts.push(`${current}[2:v]alphamerge[vm]`);
            current = '[vm]';
        }

        if (assPath !== null && position !== 'below') {
            parts.push(`${current}ass=${basename(assPath)}[va]`);
            current = '[va]';
        }

        const overlay = `[0:v]${current}overlay=${String(region.x)}:${String(region.y)}:shortest=1`;

        if (assPath !== null && position === 'below') {
            parts.push(`${overlay}[comp]`, `[comp]ass=${basename(assPath)}[out]`);
        } else {
            parts.push(`${overlay}[out]`);
        }

        return parts.join(';');
    }
}
