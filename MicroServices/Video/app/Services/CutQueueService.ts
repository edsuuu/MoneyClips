import { mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { settings } from '@/Config/Env';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';
import { S3Storage } from '@/Services/S3Storage';
import { SerialQueueService } from '@/Services/SerialQueueService';
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
export class CutQueueService extends SerialQueueService<CutJob> {
    public constructor(
        private readonly storage: S3Storage = new S3Storage(),
        private readonly probe: Probe = new Probe(),
        private readonly ffmpeg: FfmpegRunner = new FfmpegRunner(),
        private readonly webhooks: WebhookService = new WebhookService(),
    ) {
        super();
    }

    protected async process(job: CutJob): Promise<void> {
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
                    // Fonte 4K vira H.264 level 6.0, que o decoder de hardware do
                    // navegador não toca (MEDIA_ERR_DECODE logo no início). Cap em
                    // 1080p (sai level 5.0 — o preset slower usa 8 ref frames) + teto
                    // de bitrate = decodável em qualquer navegador; o reframe gera
                    // 1080x1920, então 4K é desnecessário.
                    '-vf',
                    "scale='min(1920,iw)':'min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
                    ...encoderArgs,
                    '-maxrate',
                    '12M',
                    '-bufsize',
                    '24M',
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
