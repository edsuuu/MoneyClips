import { dirname } from 'node:path';

import { Logger } from '@/Config/Logger';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';

/** Extrai o wav mono 16kHz que o transcritor (faster-whisper) espera. */
export class AudioExtractor extends Logger {
    public constructor(private readonly ffmpeg: FfmpegRunner = new FfmpegRunner()) {
        super();
    }

    public async extract(source: string, dest: string): Promise<string> {
        this.info(`[Caption] Extraindo áudio: ${source} -> ${dest}`);

        const result = await this.ffmpeg.run(
            ['-y', '-i', source, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', dest],
            dirname(dest),
        );

        if (result.code !== 0) {
            throw new Error(`ffmpeg falhou ao extrair áudio: ${result.stderr.slice(-2000)}`);
        }

        return dest;
    }
}
