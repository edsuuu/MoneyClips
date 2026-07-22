import { dirname } from 'node:path';

import { Logger } from '@/Config/Logger';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';
import type { VideoMeta } from '@/Services/Video/Probe';

export interface StoryboardParams {
    cols: number;
    rows: number;
    interval: number;
    tile_width: number;
    tile_height: number;
}

/**
 * Grade única de miniaturas (estilo YouTube): um JPEG com N thumbnails lado a
 * lado. Uma imagem só (`-frames:v 1`) para o player carregar o scrubbing inteiro
 * num request. A grade é dimensionada para caber todos os frames amostrados —
 * qualquer excedente por arredondamento do filtro `fps` é descartado.
 */
export class StoryboardGenerator extends Logger {
    private static readonly TARGET_THUMBS = 130;
    private static readonly TILE_WIDTH = 160;

    public constructor(private readonly ffmpeg: FfmpegRunner = new FfmpegRunner()) {
        super();
    }

    public async generate(
        source: string,
        dest: string,
        meta: VideoMeta,
    ): Promise<StoryboardParams> {
        const params = this.plan(meta);
        const filter =
            `fps=1/${String(params.interval)},` +
            `scale=${String(params.tile_width)}:${String(params.tile_height)},` +
            `tile=${String(params.cols)}x${String(params.rows)}`;

        const result = await this.ffmpeg.run(
            ['-y', '-i', source, '-vf', filter, '-frames:v', '1', '-q:v', '3', dest],
            dirname(dest),
        );

        if (result.code !== 0) {
            throw new Error(`ffmpeg falhou no storyboard: ${result.stderr.slice(-2000)}`);
        }

        return params;
    }

    private plan(meta: VideoMeta): StoryboardParams {
        const duration = Math.max(1, meta.durationSeconds);
        const interval = Math.max(1, Math.round(duration / StoryboardGenerator.TARGET_THUMBS));
        const count = Math.max(1, Math.ceil(duration / interval));
        const cols = Math.ceil(Math.sqrt(count));
        const rows = Math.ceil(count / cols);

        const tileWidth = StoryboardGenerator.TILE_WIDTH;
        const ratio = meta.width > 0 ? meta.height / meta.width : 9 / 16;
        const tileHeight = Math.max(2, 2 * Math.round((tileWidth * ratio) / 2));

        return { cols, rows, interval, tile_width: tileWidth, tile_height: tileHeight };
    }
}
