/**
 * Reencode do vídeo antes do upload no TikTok.
 *
 * O TikTok recusa alguns vídeos por "baixa qualidade" — causado por bitrate
 * insuficiente nos originais do YouTube. Este módulo inspeciona o arquivo
 * baixado via ffprobe e, se o bitrate do stream de vídeo estiver abaixo do
 * limiar configurável, recodifica em qualidade constante (CQ/CRF 18):
 * h264_nvenc (GPU NVIDIA) quando disponível, libx264 (CPU) como fallback.
 *
 * Função pura `reencodeIfNeeded` — devolve o path do arquivo a publicar
 * (recodificado ou o original, quando não foi preciso ou algo falhou).
 */

import { execFile, spawn } from 'node:child_process';
import { basename, dirname, extname, join } from 'node:path';
import { promisify } from 'node:util';

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';

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

// Cache da detecção de NVENC — evita rodar probes de runtime a cada vídeo.
let nvencAvailable: boolean | null = null;

async function detectNvenc(): Promise<boolean> {
    if (nvencAvailable !== null) {
        return nvencAvailable;
    }
    try {
        const { stdout } = await execFileAsync('ffmpeg', ['-hide_banner', '-encoders']);
        if (!stdout.includes('h264_nvenc')) {
            nvencAvailable = false;
            return nvencAvailable;
        }

        // `ffmpeg -encoders` mostra h264_nvenc quando o binário foi compilado
        // com suporte, mesmo sem driver/GPU CUDA disponível no container. Faça
        // um encode mínimo para validar o runtime antes de escolher NVENC.
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
        nvencAvailable = true;
    } catch (error) {
        logger.warn(
            `[Reencode] h264_nvenc indisponível em runtime (${processErrorMessage(error)}); usando libx264.`,
        );
        nvencAvailable = false;
    }
    return nvencAvailable;
}

async function probe(videoPath: string): Promise<VideoMeta> {
    const { stdout } = await execFileAsync('ffprobe', [
        '-v', 'quiet',
        '-print_format', 'json',
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
function buildArgs(input: string, output: string, useNvenc: boolean): string[] {
    const common = [
        '-pix_fmt', 'yuv420p',
        '-profile:v', 'high',
        '-level', '4.1',
        '-c:a', 'aac',
        '-b:a', '192k',
        '-ar', '44100',
        '-movflags', '+faststart',
        '-y', output,
    ];

    if (useNvenc) {
        return [
            // Decode fica em CPU. Forçar `-hwaccel cuda` quebra inputs AV1
            // quando o container não tem decoder CUDA/libcuda, mesmo que NVENC
            // esteja listado no ffmpeg.
            '-i', input,
            '-c:v', 'h264_nvenc',
            '-preset', 'p7',
            '-tune', 'hq',
            '-rc', 'vbr',
            '-cq', '18',
            '-b:v', '0',
            '-maxrate', '80M',
            '-bufsize', '160M',
            ...common,
        ];
    }

    return ['-i', input, '-c:v', 'libx264', '-crf', '18', '-preset', 'slow', ...common];
}

// spawn (não execFile) para herdar o stderr do ffmpeg e mostrar progresso ao vivo.
function runFfmpeg(args: string[]): Promise<void> {
    return new Promise((resolve, reject) => {
        const proc = spawn('ffmpeg', args, { stdio: ['ignore', 'ignore', 'inherit'] });
        proc.on('close', (code) =>
            code === 0 ? resolve() : reject(new Error(`ffmpeg saiu com código ${code}`)),
        );
        proc.on('error', reject);
    });
}

const fmtMbps = (bps: number): string => (bps > 0 ? `${(bps / 1_000_000).toFixed(2)} Mbps` : 'N/A');
const fmtMb = (bytes: number): string => (bytes > 0 ? `${(bytes / 1024 ** 2).toFixed(2)} MB` : 'N/A');

const fmtDuration = (seconds: number): string => {
    const sec = Math.round(seconds);
    const min = Math.floor(sec / 60);
    return min > 0 ? `${min}m ${sec % 60}s` : `${sec}s`;
};

const pctDelta = (before: number, after: number): string => {
    if (before <= 0) {
        return '?';
    }
    const delta = ((after - before) / before) * 100;
    return `${delta >= 0 ? '+' : ''}${delta.toFixed(1)}%`;
};

function processErrorMessage(error: unknown): string {
    if (error instanceof Error) {
        const maybeProcessError = error as Error & { stderr?: unknown };
        const stderr =
            typeof maybeProcessError.stderr === 'string' ? maybeProcessError.stderr.trim() : '';

        return stderr !== '' ? (stderr.split('\n').at(-1) ?? error.message) : error.message;
    }

    return String(error);
}

/**
 * Recodifica o vídeo se o bitrate estiver abaixo do limiar. Retorna o path do
 * arquivo a publicar (o recodificado `_HQ.mp4`, ou o original quando o reencode
 * é desnecessário/desabilitado, ou se ffprobe/ffmpeg falharem — nunca derruba o
 * upload por causa do reencode).
 */
export async function reencodeIfNeeded(videoPath: string, videoId: string): Promise<string> {
    if (!settings.reencodeEnabled) {
        logger.info('[Reencode] Desabilitado (REENCODE_ENABLED=false); usando arquivo original.');
        return videoPath;
    }

    let before: VideoMeta;
    try {
        before = await probe(videoPath);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        logger.warn(`[Reencode] ffprobe falhou (${message}); usando arquivo original.`);
        return videoPath;
    }

    const useNvenc = await detectNvenc();
    logger.info(`[Reencode] Encoder: ${useNvenc ? 'h264_nvenc (GPU)' : 'libx264 (CPU fallback)'}`);
    logger.info('[Reencode] Metadados originais:');
    logger.info(`    youtube_id  : ${videoId}`);
    logger.info(`    resolução   : ${before.width ?? '?'}x${before.height ?? '?'}`);
    logger.info(`    duração     : ${fmtDuration(before.duration)}`);
    logger.info(`    bitrate vid : ${fmtMbps(before.videoBitrate)}`);
    logger.info(`    codec       : ${before.codec} (${before.profile})`);
    logger.info(`    tamanho     : ${fmtMb(before.fileSize)}`);

    const thresholdBps = settings.reencodeBitrateThresholdKbps * 1000;
    if (before.videoBitrate > 0 && before.videoBitrate >= thresholdBps) {
        logger.info(
            `[Reencode] Bitrate acima do limiar (${settings.reencodeBitrateThresholdKbps} kbps); ` +
                'reencode não necessário.',
        );
        return videoPath;
    }

    const outputPath = join(
        dirname(videoPath),
        `${basename(videoPath, extname(videoPath))}_HQ.mp4`,
    );

    logger.info('[Reencode] Recodificando (CQ/CRF 18)...');
    const startedAt = Date.now();
    try {
        await runFfmpeg(buildArgs(videoPath, outputPath, useNvenc));
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        // NVENC pode falhar em runtime (driver ausente no host) mesmo detectado
        // no `-encoders`; nesse caso cai para libx264 e não redetecta NVENC.
        if (useNvenc) {
            logger.warn(`[Reencode] NVENC falhou (${message}); tentando libx264 (CPU)...`);
            nvencAvailable = false;
            try {
                await runFfmpeg(buildArgs(videoPath, outputPath, false));
            } catch (cpuError) {
                const cpuMessage = cpuError instanceof Error ? cpuError.message : String(cpuError);
                logger.warn(
                    `[Reencode] libx264 também falhou (${cpuMessage}); usando arquivo original.`,
                );
                return videoPath;
            }
        } else {
            logger.warn(`[Reencode] libx264 falhou (${message}); usando arquivo original.`);
            return videoPath;
        }
    }

    let after: VideoMeta;
    try {
        after = await probe(outputPath);
    } catch {
        // Reencode gerou o arquivo mas ffprobe do resultado falhou — publica o
        // recodificado mesmo assim (o objetivo já foi cumprido).
        logger.info(`[Reencode] Concluído em ${Date.now() - startedAt}ms.`);
        return outputPath;
    }

    logger.info(`[Reencode] Concluído em ${Date.now() - startedAt}ms.`);
    logger.info('[Reencode] Metadados finais:');
    logger.info(
        `    bitrate vid : ${fmtMbps(after.videoBitrate)}   (${pctDelta(before.videoBitrate, after.videoBitrate)})`,
    );
    logger.info(
        `    tamanho     : ${fmtMb(after.fileSize)}     (${pctDelta(before.fileSize, after.fileSize)})`,
    );

    return outputPath;
}
