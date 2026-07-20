/**
 * Reencode do vídeo antes da publicação nas redes.
 *
 * O TikTok recusa alguns vídeos por "baixa qualidade" — causado por bitrate
 * insuficiente nos originais do YouTube. Inspeciona o arquivo via ffprobe e, se
 * o bitrate do stream de vídeo estiver abaixo do limiar configurável, recodifica
 * em qualidade constante (CQ/CRF 18): h264_nvenc (GPU NVIDIA) quando disponível,
 * libx264 (CPU) como fallback.
 */

import { execFile, spawn } from 'node:child_process';
import { basename, dirname, extname, join } from 'node:path';
import { promisify } from 'node:util';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';

const execFileAsync = promisify(execFile);

/** Recorte tipado da saída do ffprobe (`-show_streams -show_format`). */
interface FfprobeStream {
    codec_type?: string;
    codec_name?: string;
    profile?: string;
    width?: number;
    height?: number;
    bit_rate?: string;
    r_frame_rate?: string;
}

interface FfprobeFormat {
    duration?: string;
    size?: string;
    bit_rate?: string;
}

interface FfprobeOutput {
    streams?: FfprobeStream[];
    format?: FfprobeFormat;
}

/** Metadados normalizados do vídeo, prontos para log e comparação. */
interface VideoMeta {
    width: number | null;
    height: number | null;
    codec: string;
    profile: string;
    videoBitrate: number; // bps
    duration: number; // segundos
    fileSize: number; // bytes
}

export class VideoReencoder extends Logger {
    // Cache da detecção de NVENC — evita rodar probes de runtime a cada vídeo.
    private nvencAvailable: boolean | null = null;

    /**
     * Recodifica o vídeo se o bitrate estiver abaixo do limiar. Retorna o path
     * do arquivo a publicar (o recodificado `_HQ.mp4`, ou o original quando o
     * reencode é desnecessário/desabilitado, ou se ffprobe/ffmpeg falharem —
     * nunca derruba o upload por causa do reencode).
     */
    public async reencodeIfNeeded(videoPath: string, videoId: string): Promise<string> {
        if (!settings.reencodeEnabled) {
            this.info('[Reencode] Desabilitado (REENCODE_ENABLED=false); usando arquivo original.');

            return videoPath;
        }

        let before: VideoMeta;
        try {
            before = await this.probe(videoPath);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.warn(`[Reencode] ffprobe falhou (${message}); usando arquivo original.`);

            return videoPath;
        }

        const useNvenc = await this.detectNvenc();
        this.info(
            `[Reencode] Encoder: ${useNvenc ? 'h264_nvenc (GPU)' : 'libx264 (CPU fallback)'}`,
        );
        this.info('[Reencode] Metadados originais:');
        this.info(`    youtube_id  : ${videoId}`);
        this.info(`    resolução   : ${before.width ?? '?'}x${before.height ?? '?'}`);
        this.info(`    duração     : ${this.formatDuration(before.duration)}`);
        this.info(`    bitrate vid : ${this.formatMbps(before.videoBitrate)}`);
        this.info(`    codec       : ${before.codec} (${before.profile})`);
        this.info(`    tamanho     : ${this.formatMb(before.fileSize)}`);

        const thresholdBps = settings.reencodeBitrateThresholdKbps * 1000;
        if (before.videoBitrate > 0 && before.videoBitrate >= thresholdBps) {
            this.info(
                `[Reencode] Bitrate acima do limiar (${String(settings.reencodeBitrateThresholdKbps)} kbps); ` +
                    'reencode não necessário.',
            );

            return videoPath;
        }

        const outputPath = join(
            dirname(videoPath),
            `${basename(videoPath, extname(videoPath))}_HQ.mp4`,
        );

        this.info('[Reencode] Recodificando (CQ/CRF 18)...');
        const startedAt = Date.now();

        if (!(await this.encode(videoPath, outputPath, useNvenc))) {
            return videoPath;
        }

        let after: VideoMeta;
        try {
            after = await this.probe(outputPath);
        } catch {
            // Reencode gerou o arquivo mas ffprobe do resultado falhou — publica
            // o recodificado mesmo assim (o objetivo já foi cumprido).
            this.info(`[Reencode] Concluído em ${String(Date.now() - startedAt)}ms.`);

            return outputPath;
        }

        this.info(`[Reencode] Concluído em ${String(Date.now() - startedAt)}ms.`);
        this.info('[Reencode] Metadados finais:');
        this.info(
            `    bitrate vid : ${this.formatMbps(after.videoBitrate)}   (${this.percentDelta(before.videoBitrate, after.videoBitrate)})`,
        );
        this.info(
            `    tamanho     : ${this.formatMb(after.fileSize)}     (${this.percentDelta(before.fileSize, after.fileSize)})`,
        );

        return outputPath;
    }

    /** Roda o ffmpeg com fallback de NVENC pra CPU. `false` = nada utilizável. */
    private async encode(input: string, output: string, useNvenc: boolean): Promise<boolean> {
        try {
            await this.runFfmpeg(this.buildArgs(input, output, useNvenc));

            return true;
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);

            if (!useNvenc) {
                this.warn(`[Reencode] libx264 falhou (${message}); usando arquivo original.`);

                return false;
            }

            // NVENC pode falhar em runtime (driver ausente no host) mesmo
            // detectado no `-encoders`; cai para libx264 e não redetecta NVENC.
            this.warn(`[Reencode] NVENC falhou (${message}); tentando libx264 (CPU)...`);
            this.nvencAvailable = false;

            try {
                await this.runFfmpeg(this.buildArgs(input, output, false));

                return true;
            } catch (cpuError) {
                const cpuMessage = cpuError instanceof Error ? cpuError.message : String(cpuError);
                this.warn(
                    `[Reencode] libx264 também falhou (${cpuMessage}); usando arquivo original.`,
                );

                return false;
            }
        }
    }

    private async detectNvenc(): Promise<boolean> {
        if (this.nvencAvailable !== null) {
            return this.nvencAvailable;
        }

        try {
            const { stdout } = await execFileAsync('ffmpeg', ['-hide_banner', '-encoders']);
            if (!stdout.includes('h264_nvenc')) {
                this.nvencAvailable = false;

                return this.nvencAvailable;
            }

            // `ffmpeg -encoders` mostra h264_nvenc quando o binário foi compilado
            // com suporte, mesmo sem driver/GPU CUDA disponível no host. Faça um
            // encode mínimo para validar o runtime antes de escolher NVENC.
            await execFileAsync(
                'ffmpeg',
                [
                    '-hide_banner',
                    '-v',
                    'error',
                    '-f',
                    'lavfi',
                    '-i',
                    'color=size=64x64:rate=1:duration=1',
                    '-frames:v',
                    '1',
                    '-an',
                    '-c:v',
                    'h264_nvenc',
                    '-preset',
                    'p1',
                    '-f',
                    'null',
                    '-',
                ],
                { timeout: 10_000 },
            );
            this.nvencAvailable = true;
        } catch (error) {
            this.warn(
                `[Reencode] h264_nvenc indisponível em runtime (${this.processErrorMessage(error)}); usando libx264.`,
            );
            this.nvencAvailable = false;
        }

        return this.nvencAvailable;
    }

    private async probe(videoPath: string): Promise<VideoMeta> {
        const { stdout } = await execFileAsync('ffprobe', [
            '-v',
            'quiet',
            '-print_format',
            'json',
            '-show_streams',
            '-show_format',
            videoPath,
        ]);

        const data = JSON.parse(stdout) as FfprobeOutput;
        const video = (data.streams ?? []).find((s) => s.codec_type === 'video') ?? {};
        const fmt = data.format ?? {};

        return {
            width: video.width ?? null,
            height: video.height ?? null,
            codec: video.codec_name ?? 'N/A',
            profile: video.profile ?? 'N/A',
            videoBitrate: Number.parseInt(video.bit_rate ?? fmt.bit_rate ?? '0', 10),
            duration: Number.parseFloat(fmt.duration ?? '0'),
            fileSize: Number.parseInt(fmt.size ?? '0', 10),
        };
    }

    // NVENC (GPU): -cq 18 (qualidade constante), -b:v 0 (sem teto), -maxrate trava o pico.
    // x264 (CPU): -crf 18, -preset slow. Ambos: yuv420p + faststart + aac 192k = TikTok/YT ok.
    private buildArgs(input: string, output: string, useNvenc: boolean): string[] {
        const common = [
            '-pix_fmt',
            'yuv420p',
            '-profile:v',
            'high',
            '-level',
            '4.1',
            '-c:a',
            'aac',
            '-b:a',
            '192k',
            '-ar',
            '44100',
            '-movflags',
            '+faststart',
            '-y',
            output,
        ];

        if (useNvenc) {
            return [
                // Decode fica em CPU. Forçar `-hwaccel cuda` quebra inputs AV1
                // quando o host não tem decoder CUDA/libcuda, mesmo que NVENC
                // esteja listado no ffmpeg.
                '-i',
                input,
                '-c:v',
                'h264_nvenc',
                '-preset',
                'p7',
                '-tune',
                'hq',
                '-rc',
                'vbr',
                '-cq',
                '18',
                '-b:v',
                '0',
                '-maxrate',
                '80M',
                '-bufsize',
                '160M',
                ...common,
            ];
        }

        return ['-i', input, '-c:v', 'libx264', '-crf', '18', '-preset', 'slow', ...common];
    }

    // spawn (não execFile) para herdar o stderr do ffmpeg e mostrar progresso ao vivo.
    private runFfmpeg(args: string[]): Promise<void> {
        return new Promise((resolve, reject) => {
            const proc = spawn('ffmpeg', args, { stdio: ['ignore', 'ignore', 'inherit'] });
            proc.on('close', (code) =>
                code === 0
                    ? resolve()
                    : reject(new Error(`ffmpeg saiu com código ${String(code)}`)),
            );
            proc.on('error', reject);
        });
    }

    private formatMbps(bps: number): string {
        return bps > 0 ? `${(bps / 1_000_000).toFixed(2)} Mbps` : 'N/A';
    }

    private formatMb(bytes: number): string {
        return bytes > 0 ? `${(bytes / 1024 ** 2).toFixed(2)} MB` : 'N/A';
    }

    private formatDuration(seconds: number): string {
        const sec = Math.round(seconds);
        const min = Math.floor(sec / 60);

        return min > 0 ? `${String(min)}m ${String(sec % 60)}s` : `${String(sec)}s`;
    }

    private percentDelta(before: number, after: number): string {
        if (before <= 0) {
            return '?';
        }

        const delta = ((after - before) / before) * 100;

        return `${delta >= 0 ? '+' : ''}${delta.toFixed(1)}%`;
    }

    private processErrorMessage(error: unknown): string {
        if (error instanceof Error) {
            const maybeProcessError = error as Error & { stderr?: unknown };
            const stderr =
                typeof maybeProcessError.stderr === 'string' ? maybeProcessError.stderr.trim() : '';

            return stderr !== '' ? (stderr.split('\n').at(-1) ?? error.message) : error.message;
        }

        return String(error);
    }
}
