/**
 * Variante "vertical": 9:16 com pillarbox borrado — o mesmo frame entra duas
 * vezes (split), um lado vira fundo desfocado preenchendo 1080x1920 e o outro
 * fica centralizado no tamanho original.
 *
 * O `gblur` roda em CPU e é o passo mais lento do pipeline inteiro.
 */

import { basename, dirname } from 'node:path';

import { Logger } from '@/Config/Logger';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';

export class Reframer extends Logger {
    public constructor(private readonly ffmpeg: FfmpegRunner = new FfmpegRunner()) {
        super();
    }

    public async reframeAndBurn(
        source: string,
        assPath: string | null,
        out: string,
        width = 1080,
        height = 1920,
    ): Promise<string> {
        const cwd = dirname(out);
        const filter = this.buildFilter(width, height, assPath);

        this.info(`[Caption] Reframe ${String(width)}x${String(height)}: ${out}`);

        await this.ffmpeg.runWithFallback(
            (encoderArgs) => [
                '-y',
                '-i',
                source,
                '-filter_complex',
                filter,
                '-map',
                '[out]',
                '-map',
                '0:a?',
                ...encoderArgs,
                '-c:a',
                'copy',
                basename(out),
            ],
            cwd,
            'vertical',
        );

        return out;
    }

    private buildFilter(width: number, height: number, assPath: string | null): string {
        const w = String(width);
        const h = String(height);
        const base =
            `[0:v]split=2[bg][fg];` +
            `[bg]scale=${w}:${h}:force_original_aspect_ratio=increase,` +
            `crop=${w}:${h},gblur=sigma=22[bgb];` +
            `[fg]scale=${w}:${h}:force_original_aspect_ratio=decrease[fgs];` +
            `[bgb][fgs]overlay=(W-w)/2:(H-h)/2`;

        if (assPath === null) {
            return `${base}[out]`;
        }

        return `${base}[v];[v]ass=${basename(assPath)}[out]`;
    }
}
