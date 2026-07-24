import { spawn } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';
import type { Rendition } from '@/Services/Video/LadderBuilder';
import type { VideoMeta } from '@/Services/Video/Probe';

/**
 * Empacotamento HLS/ABR com ffmpeg. Um único decode alimenta todos os degraus
 * via `split` — rodar um ffmpeg por rendition decodificaria a fonte N vezes.
 * Os keyframes são forçados no mesmo instante em todas as variantes
 * (`-force_key_frames` + `-sc_threshold 0`): sem isso o player trava ao trocar
 * de qualidade, porque os segmentos não são intercambiáveis.
 */
export class HLSPackager extends Logger {
    private encoderCache: string | null = null;

    public async package(
        input: string,
        outputDir: string,
        ladder: Rendition[],
        meta: VideoMeta,
        remux: boolean,
        audioPath: string | null,
        onProgress: (percent: number) => void,
    ): Promise<string> {
        for (const rendition of ladder) {
            await mkdir(join(outputDir, rendition.name), { recursive: true });
        }

        if (remux) {
            await this.runFfmpeg(input, outputDir, ladder, meta, 'copy', audioPath, onProgress);
            return 'copy';
        }

        const codec = await this.resolveEncoder();

        try {
            await this.runFfmpeg(input, outputDir, ladder, meta, codec, audioPath, onProgress);
            return codec;
        } catch (error) {
            if (codec === 'libx264') {
                throw error;
            }
            // O hardware pode recusar em runtime (driver, sessão esgotada, Mac
            // headless): CPU é melhor que perder um empacotamento de horas.
            this.warn(`${codec} falhou em runtime, refazendo em CPU: ${(error as Error).message}`);
            await this.runFfmpeg(input, outputDir, ladder, meta, 'libx264', audioPath, onProgress);
            return 'libx264';
        }
    }

    public extractPoster(input: string, output: string, durationSeconds: number): Promise<void> {
        const at = Math.min(10, Math.max(0, durationSeconds * 0.1));
        const args = [
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-ss',
            at.toFixed(2),
            '-i',
            input,
            '-frames:v',
            '1',
            '-vf',
            'scale=640:-2',
            output,
        ];

        return new Promise((resolve, reject) => {
            const child = spawn('ffmpeg', args, { stdio: ['ignore', 'ignore', 'ignore'] });

            child.on('error', reject);
            child.on('close', (code) => {
                if (code === 0) {
                    resolve();
                    return;
                }
                reject(new Error(`Falha ao extrair o poster (código ${String(code)}).`));
            });
        });
    }

    private async resolveEncoder(): Promise<string> {
        if (this.encoderCache !== null) {
            return this.encoderCache;
        }

        this.encoderCache = await this.pickEncoder();
        this.info(`Encoder: ${this.encoderCache} (SO ${process.platform})`);

        return this.encoderCache;
    }

    private async pickEncoder(): Promise<string> {
        if (settings.encoder === 'cpu') {
            return 'libx264';
        }

        // macOS não tem NVIDIA — a GPU é acelerada via VideoToolbox (Apple).
        if (process.platform === 'darwin') {
            if (await this.encoderWorks('h264_videotoolbox')) {
                return 'h264_videotoolbox';
            }
            this.warn('VideoToolbox indisponível — empacotando em CPU (libx264).');

            return 'libx264';
        }

        // Linux/Windows: NVENC quando há GPU NVIDIA utilizável.
        if (await this.encoderWorks('h264_nvenc')) {
            return 'h264_nvenc';
        }
        this.warn('GPU NVIDIA/NVENC indisponível — empacotando em CPU (libx264).');

        return 'libx264';
    }

    /**
     * Encoder compilado no ffmpeg E que roda de verdade — o test-encode é a
     * detecção do hardware: h264_nvenc só passa com GPU NVIDIA + driver;
     * h264_videotoolbox só passa no macOS. Presença nos `-encoders` sozinha não
     * garante runtime.
     */
    private async encoderWorks(codec: string): Promise<boolean> {
        const listed = await this.spawnOk('ffmpeg', ['-hide_banner', '-encoders'], codec);

        if (!listed) {
            return false;
        }

        return this.spawnExitZero('ffmpeg', [
            '-hide_banner',
            '-loglevel',
            'error',
            '-f',
            'lavfi',
            '-i',
            'nullsrc=s=64x64:d=0.1',
            '-c:v',
            codec,
            '-f',
            'null',
            '-',
        ]);
    }

    private buildArgs(
        input: string,
        outputDir: string,
        ladder: Rendition[],
        meta: VideoMeta,
        codec: string,
        audioPath: string | null,
    ): string[] {
        const seg = settings.segmentSeconds;
        const args = ['-hide_banner', '-y'];

        if (codec === 'h264_nvenc') {
            args.push('-hwaccel', 'cuda');
        }

        args.push('-i', input);

        if (codec === 'copy') {
            args.push('-c', 'copy');
        } else {
            args.push(...this.encodeArgs(ladder, meta, codec, seg));
        }

        const streamMap = ladder
            .map((rendition, index) =>
                meta.hasAudio
                    ? `v:${String(index)},a:${String(index)},name:${rendition.name}`
                    : `v:${String(index)},name:${rendition.name}`,
            )
            .join(' ');

        args.push(
            '-f',
            'hls',
            '-hls_time',
            String(seg),
            '-hls_playlist_type',
            'vod',
            '-hls_flags',
            'independent_segments',
            '-hls_segment_type',
            'fmp4',
            // Sem `%v`: o nome é resolvido relativo ao diretório da playlist, que
            // já é o da rendition — com `%v` viraria `360p/360p/init.mp4`.
            '-hls_fmp4_init_filename',
            'init.mp4',
            '-hls_segment_filename',
            join(outputDir, '%v', 'seg_%05d.m4s'),
            '-master_pl_name',
            'master.m3u8',
            '-var_stream_map',
            streamMap,
            '-progress',
            'pipe:1',
            '-nostats',
            join(outputDir, '%v', 'index.m3u8'),
        );

        // Áudio WAV mono 16kHz extraído no mesmo decode do HLS (saída à parte,
        // fora do diretório do HLS): é o input nativo do transcriber, e como o
        // comando já decodifica o áudio das renditions, este `.wav` não custa
        // um segundo decode do 4K.
        if (audioPath !== null && meta.hasAudio) {
            args.push('-map', 'a:0', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', audioPath);
        }

        return args;
    }

    private encodeArgs(ladder: Rendition[], meta: VideoMeta, codec: string, seg: number): string[] {
        const splits = ladder.map((_, index) => `[v${String(index)}]`).join('');
        const scales = ladder
            .map(
                (rendition, index) =>
                    `[v${String(index)}]scale=-2:${String(rendition.height)}[v${String(index)}o]`,
            )
            .join(';');

        const args = ['-filter_complex', `[0:v]split=${String(ladder.length)}${splits};${scales}`];

        ladder.forEach((rendition, index) => {
            const maxrate = Math.round(rendition.videoBitrateKbps * 1.07);
            const bufsize = Math.round(rendition.videoBitrateKbps * 1.5);
            args.push(
                '-map',
                `[v${String(index)}o]`,
                `-c:v:${String(index)}`,
                codec,
                `-b:v:${String(index)}`,
                `${String(rendition.videoBitrateKbps)}k`,
                `-maxrate:v:${String(index)}`,
                `${String(maxrate)}k`,
                `-bufsize:v:${String(index)}`,
                `${String(bufsize)}k`,
            );
        });

        if (codec === 'h264_nvenc') {
            args.push('-preset', 'p4');
        } else if (codec === 'libx264') {
            args.push('-preset', 'veryfast');
        } else if (codec === 'h264_videotoolbox') {
            // VideoToolbox não usa -preset; -allow_sw deixa cair pro encoder de
            // software da Apple num Mac sem sessão de HW (headless/CI).
            args.push('-allow_sw', '1');

            // Medido num Mac (Darwin 25.5): o VideoToolbox IGNORA o
            // -force_key_frames abaixo e emite keyframe a cada ~0.4s — 75 num
            // clipe de 30s, contra os 5 do libx264. As renditions continuam
            // alinhadas (todas erram igual), então o player não trava, mas o
            // encode desperdiça bits em I-frame. Ele respeita `-g`, então o GOP
            // vai explícito em frames.
            args.push('-g', String(Math.max(1, Math.round(meta.fps * seg))));
        }

        args.push(
            '-pix_fmt',
            'yuv420p',
            '-sc_threshold',
            '0',
            '-force_key_frames',
            `expr:gte(t,n_forced*${String(seg)})`,
        );

        if (meta.hasAudio) {
            ladder.forEach((rendition, index) => {
                args.push(
                    '-map',
                    'a:0',
                    `-b:a:${String(index)}`,
                    `${String(rendition.audioBitrateKbps)}k`,
                );
            });
            args.push('-c:a', 'aac', '-ac', '2');
        }

        return args;
    }

    private runFfmpeg(
        input: string,
        outputDir: string,
        ladder: Rendition[],
        meta: VideoMeta,
        codec: string,
        audioPath: string | null,
        onProgress: (percent: number) => void,
    ): Promise<void> {
        const args = this.buildArgs(input, outputDir, ladder, meta, codec, audioPath);
        this.info(`ffmpeg ${args.join(' ')}`);

        return new Promise((resolve, reject) => {
            const child = spawn('ffmpeg', args, { stdio: ['ignore', 'pipe', 'pipe'] });
            let stderr = '';
            let buffer = '';

            child.stdout.on('data', (chunk: Buffer) => {
                buffer += chunk.toString();
                const lines = buffer.split('\n');
                buffer = lines.pop() ?? '';

                for (const line of lines) {
                    const [key, value] = line.split('=');
                    if (key === 'out_time_ms' && meta.durationSeconds > 0) {
                        const seconds = Number(value) / 1_000_000;
                        onProgress(
                            Math.min(99, Math.round((seconds / meta.durationSeconds) * 100)),
                        );
                    }
                }
            });

            // stderr do ffmpeg é volumoso: guarda só o fim, que é onde o erro aparece.
            child.stderr.on('data', (chunk: Buffer) => {
                stderr = (stderr + chunk.toString()).slice(-4000);
            });

            child.on('error', reject);
            child.on('close', (code) => {
                if (code === 0) {
                    resolve();
                    return;
                }
                reject(new Error(`ffmpeg saiu com código ${String(code)}: ${stderr.trim()}`));
            });
        });
    }

    private spawnOk(command: string, args: string[], needle: string): Promise<boolean> {
        return new Promise((resolve) => {
            const child = spawn(command, args, { stdio: ['ignore', 'pipe', 'ignore'] });
            let out = '';

            child.stdout.on('data', (chunk: Buffer) => {
                out += chunk.toString();
            });
            child.on('error', () => resolve(false));
            child.on('close', () => resolve(out.includes(needle)));
        });
    }

    private spawnExitZero(command: string, args: string[]): Promise<boolean> {
        return new Promise((resolve) => {
            const child = spawn(command, args, { stdio: ['ignore', 'ignore', 'ignore'] });
            child.on('error', () => resolve(false));
            child.on('close', (code) => resolve(code === 0));
        });
    }
}
