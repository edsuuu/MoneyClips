import { randomUUID } from 'node:crypto';
import { mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';
import { S3Storage } from '@/Services/S3Storage';
import { Probe } from '@/Services/Video/Probe';
import { WebhookService } from '@/Services/WebhookService';

interface CutJob {
    uuid: string;
    cutUuid: string;
    videoKey: string;
    startSeconds: number;
    endSeconds: number;
    clipKey: string;
    audioKey: string;
    webhookUrl: string;
}

/**
 * Recorta um trecho start–end do vídeo original com re-encode (corte
 * frame-exato; -c copy cairia no keyframe anterior) e extrai o WAV do trecho na
 * mesma passada, pro Laravel mandar transcrever. Fila serial em promise-chain,
 * como as demais — ffmpeg monopoliza CPU/GPU.
 */
export class CutQueueService extends Logger {
    private chain: Promise<unknown> = Promise.resolve();
    private pending = 0;

    public constructor(
        private readonly storage: S3Storage = new S3Storage(),
        private readonly probe: Probe = new Probe(),
        private readonly ffmpeg: FfmpegRunner = new FfmpegRunner(),
        private readonly webhooks: WebhookService = new WebhookService(),
    ) {
        super();
    }

    public size(): number {
        return this.pending;
    }

    public enqueue(input: Omit<CutJob, 'uuid'>): string {
        const job: CutJob = { uuid: randomUUID(), ...input };
        this.pending += 1;

        const run = async (): Promise<void> => {
            try {
                await this.process(job);
            } finally {
                this.pending -= 1;
            }
        };

        this.chain = this.chain.then(run, run).catch(() => undefined);

        return job.uuid;
    }

    public async drain(): Promise<void> {
        await this.chain;
    }

    private async process(job: CutJob): Promise<void> {
        const jobDir = join(this.workRoot(), job.uuid);
        const duration = job.endSeconds - job.startSeconds;

        this.info('='.repeat(50));
        this.info(
            `Corte solicitado: ${job.cutUuid} (${job.videoKey} ${String(job.startSeconds)}s–${String(job.endSeconds)}s)`,
        );

        try {
            await mkdir(jobDir, { recursive: true });
            await this.storage.download(job.videoKey, join(jobDir, 'source'));

            const meta = await this.probe.read(join(jobDir, 'source'));

            await this.ffmpeg.runWithFallback(
                (encoderArgs) => [
                    '-hide_banner',
                    '-y',
                    '-ss',
                    String(job.startSeconds),
                    '-t',
                    String(duration),
                    '-i',
                    'source',
                    ...encoderArgs,
                    '-c:a',
                    'aac',
                    '-b:a',
                    '192k',
                    '-movflags',
                    '+faststart',
                    'clip.mp4',
                    ...(meta.hasAudio
                        ? [
                              '-map',
                              'a:0',
                              '-ac',
                              '1',
                              '-ar',
                              '16000',
                              '-c:a',
                              'pcm_s16le',
                              'audio.wav',
                          ]
                        : []),
                ],
                jobDir,
                'cut',
            );

            await this.storage.uploadFile(join(jobDir, 'clip.mp4'), job.clipKey);
            const audio = meta.hasAudio
                ? await this.uploadAudio(job, join(jobDir, 'audio.wav'))
                : false;

            await this.webhooks.send(job.webhookUrl, {
                uuid: job.uuid,
                cut_uuid: job.cutUuid,
                status: 'done',
                duration_seconds: duration,
                audio,
            });

            this.info(`Corte concluído: ${job.cutUuid}`);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.error(`Corte falhou (${job.cutUuid}): ${message}`);

            await this.webhooks.send(job.webhookUrl, {
                uuid: job.uuid,
                cut_uuid: job.cutUuid,
                status: 'failed',
                error: message,
            });
        } finally {
            await rm(jobDir, { recursive: true, force: true }).catch(() => undefined);
        }
    }

    private async uploadAudio(job: CutJob, audioPath: string): Promise<boolean> {
        try {
            await this.storage.uploadFile(audioPath, job.audioKey);
            return true;
        } catch (error) {
            // Sem o WAV o Laravel só não transcreve — não invalida o corte.
            this.warn(`Áudio do corte não enviado: ${(error as Error).message}`);
            return false;
        }
    }

    private workRoot(): string {
        return settings.workDir !== '' ? settings.workDir : join(tmpdir(), 'cut-jobs');
    }
}

export const cutQueue = new CutQueueService();
