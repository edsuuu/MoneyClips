import { basename, dirname } from 'node:path';

import { Logger } from '@/Config/Logger';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';

/** Variante "original": queima a legenda no vídeo na resolução da fonte. */
export class SubtitleBurner extends Logger {
    public constructor(private readonly ffmpeg: FfmpegRunner = new FfmpegRunner()) {
        super();
    }

    public async burn(source: string, assPath: string | null, out: string): Promise<string> {
        const cwd = dirname(out);
        const filter = assPath === null ? [] : ['-vf', `ass=${basename(assPath)}`];

        this.info(`[Caption] Queimando legenda: ${out}`);

        await this.ffmpeg.runWithFallback(
            (encoderArgs) => [
                '-y',
                '-i',
                source,
                ...filter,
                ...encoderArgs,
                '-c:a',
                'copy',
                basename(out),
            ],
            cwd,
            'original',
        );

        return out;
    }
}
