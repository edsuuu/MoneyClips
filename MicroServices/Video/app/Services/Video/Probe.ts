import { spawn } from 'node:child_process';

import { NotAVideoError } from '@/Exceptions/NotAVideoError';

export interface VideoMeta {
    durationSeconds: number;
    width: number;
    height: number;
    videoCodec: string;
    audioCodec: string;
    videoBitrateKbps: number;
    hasAudio: boolean;
    fps: number;
}

interface FfprobeStream {
    codec_type?: string;
    codec_name?: string;
    width?: number;
    height?: number;
    bit_rate?: string;
    avg_frame_rate?: string;
    r_frame_rate?: string;
}

interface FfprobeOutput {
    streams?: FfprobeStream[];
    format?: { duration?: string; bit_rate?: string };
}

export class Probe {
    public async read(path: string): Promise<VideoMeta> {
        let raw: string;

        try {
            raw = await this.ffprobe(path);
        } catch {
            throw new NotAVideoError('O arquivo enviado não é um vídeo legível.');
        }

        let parsed: FfprobeOutput;

        try {
            parsed = JSON.parse(raw) as FfprobeOutput;
        } catch {
            throw new NotAVideoError('O arquivo enviado não é um vídeo legível.');
        }

        const streams = parsed.streams ?? [];
        const video = streams.find((stream) => stream.codec_type === 'video');
        const audio = streams.find((stream) => stream.codec_type === 'audio');

        if (!video || !video.width || !video.height) {
            throw new NotAVideoError('O arquivo não contém stream de vídeo utilizável.');
        }

        const streamBitrate = Number(video.bit_rate ?? 0);
        const bitrate = streamBitrate > 0 ? streamBitrate : Number(parsed.format?.bit_rate ?? 0);

        return {
            durationSeconds: Math.round(Number(parsed.format?.duration ?? 0)),
            width: video.width,
            height: video.height,
            videoCodec: video.codec_name ?? '',
            audioCodec: audio?.codec_name ?? '',
            videoBitrateKbps: Math.round(bitrate / 1000),
            hasAudio: audio !== undefined,
            fps: this.parseFps(video),
        };
    }

    /** ffprobe devolve fração ("30000/1001"). Default 30 quando vier 0/0 ou lixo. */
    private parseFps(video: FfprobeStream): number {
        const raw = video.avg_frame_rate ?? video.r_frame_rate ?? '';
        const [numerator, denominator] = raw.split('/').map(Number);

        if (
            numerator === undefined ||
            denominator === undefined ||
            !Number.isFinite(numerator) ||
            !Number.isFinite(denominator) ||
            denominator === 0 ||
            numerator <= 0
        ) {
            return 30;
        }

        return numerator / denominator;
    }

    private ffprobe(path: string): Promise<string> {
        const args = [
            '-v',
            'quiet',
            '-print_format',
            'json',
            '-show_streams',
            '-show_format',
            path,
        ];

        return new Promise((resolve, reject) => {
            const child = spawn('ffprobe', args, { stdio: ['ignore', 'pipe', 'ignore'] });
            let stdout = '';

            child.stdout.on('data', (chunk: Buffer) => {
                stdout += chunk.toString();
            });

            child.on('error', reject);
            child.on('close', (code) => {
                if (code === 0) {
                    resolve(stdout);
                    return;
                }
                reject(new Error(`ffprobe saiu com código ${String(code)}`));
            });
        });
    }
}
