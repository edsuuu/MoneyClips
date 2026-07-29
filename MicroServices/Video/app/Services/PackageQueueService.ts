import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { settings } from '@/Config/Env';
import { NotAVideoError } from '@/Exceptions/NotAVideoError';
import { S3Storage } from '@/Services/S3Storage';
import { SerialQueueService } from '@/Services/SerialQueueService';
import { HLSPackager } from '@/Services/Video/HLSPackager';
import { LadderBuilder } from '@/Services/Video/LadderBuilder';
import type { VideoMeta } from '@/Services/Video/Probe';
import { Probe } from '@/Services/Video/Probe';
import type { StoryboardParams } from '@/Services/Video/StoryboardGenerator';
import { StoryboardGenerator } from '@/Services/Video/StoryboardGenerator';
import { WebhookService } from '@/Services/WebhookService';

interface PackageJob {
    uuid: string;
    videoUuid: string;
    videoKey: string;
    hlsPrefix: string;
    posterKey: string;
    audioKey: string;
    storyboardKey: string;
    webhookUrl: string;
}

/**
 * ffmpeg é pesado e um empacotamento longo monopoliza CPU/GPU: a fila serializa
 * as execuções numa promise-chain (1 por vez), como no reencode.
 */
export class PackageQueueService extends SerialQueueService<PackageJob> {
    private static readonly PROGRESS_THROTTLE_MS = 10_000;

    public constructor(
        private readonly storage: S3Storage = new S3Storage(),
        private readonly probe: Probe = new Probe(),
        private readonly ladderBuilder: LadderBuilder = new LadderBuilder(),
        private readonly packager: HLSPackager = new HLSPackager(),
        private readonly webhooks: WebhookService = new WebhookService(),
        private readonly storyboard: StoryboardGenerator = new StoryboardGenerator(),
    ) {
        super();
    }

    protected async process(job: PackageJob): Promise<void> {
        const jobDir = join(this.workRoot(), job.uuid);
        const sourcePath = join(jobDir, 'source');
        const outputDir = join(jobDir, 'out');

        this.info('='.repeat(50));
        this.info(`Empacotamento solicitado: ${job.uuid} (${job.videoKey})`);

        try {
            await mkdir(outputDir, { recursive: true });
            await this.storage.download(job.videoKey, sourcePath);

            const meta = await this.probe.read(sourcePath);
            this.info(
                `Fonte: ${String(meta.width)}x${String(meta.height)} ${String(meta.durationSeconds)}s ` +
                    `${meta.videoCodec}/${meta.audioCodec || 'sem áudio'} ${String(meta.videoBitrateKbps)}kbps`,
            );

            const ladder = this.ladderBuilder.build(meta);
            const remux = this.ladderBuilder.canRemux(meta, ladder);
            this.info(
                `Ladder: ${ladder.map((rendition) => rendition.name).join(', ')}${remux ? ' (remux, sem reencode)' : ''}`,
            );

            const audioPath = meta.hasAudio ? join(jobDir, 'audio.wav') : null;

            let lastReport = 0;
            const codec = await this.packager.package(
                sourcePath,
                outputDir,
                ladder,
                meta,
                remux,
                audioPath,
                (percent) => {
                    const now = Date.now();
                    if (now - lastReport >= PackageQueueService.PROGRESS_THROTTLE_MS) {
                        lastReport = now;
                        this.webhooks.sendProgress(job.webhookUrl, job.videoUuid, percent);
                    }
                },
            );
            this.info(`Encode: ${codec}`);

            await this.storage.uploadDirectory(outputDir, job.hlsPrefix);

            const poster = await this.extractPoster(job, sourcePath, jobDir, meta.durationSeconds);
            const audio = await this.uploadAudio(job, audioPath);
            const lowestRendition = join(outputDir, ladder[0]?.name ?? '', 'index.m3u8');
            const storyboard = await this.buildStoryboard(job, lowestRendition, jobDir, meta);
            const hash = await this.hashFile(sourcePath);

            await this.webhooks.send(job.webhookUrl, {
                uuid: job.uuid,
                video_uuid: job.videoUuid,
                status: 'done',
                duration_seconds: meta.durationSeconds,
                width: meta.width,
                height: meta.height,
                hash,
                renditions: ladder.map((rendition) => rendition.name),
                poster,
                audio,
                storyboard,
            });

            this.info(`Empacotamento concluído: ${job.uuid}`);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.error(`Empacotamento falhou (${job.uuid}): ${message}`);

            await this.webhooks.send(job.webhookUrl, {
                uuid: job.uuid,
                video_uuid: job.videoUuid,
                status: error instanceof NotAVideoError ? 'rejected' : 'failed',
                error: message,
            });
        } finally {
            await rm(jobDir, { recursive: true, force: true }).catch((error) =>
                this.warn(
                    `Falha ao limpar o diretório do job ${job.uuid}: ${(error as Error).message}`,
                ),
            );
        }
    }

    private async extractPoster(
        job: PackageJob,
        sourcePath: string,
        jobDir: string,
        durationSeconds: number,
    ): Promise<boolean> {
        const local = join(jobDir, 'poster.jpg');
        try {
            await this.packager.extractPoster(sourcePath, local, durationSeconds);
            await this.storage.uploadFile(local, job.posterKey);
            return true;
        } catch (error) {
            // Poster é enfeite: a ausência dele não invalida o empacotamento.
            this.warn(`Poster não extraído: ${(error as Error).message}`);
            return false;
        }
    }

    private async uploadAudio(job: PackageJob, audioPath: string | null): Promise<boolean> {
        if (audioPath === null) {
            return false;
        }
        try {
            await this.storage.uploadFile(audioPath, job.audioKey);
            return true;
        } catch (error) {
            // Fonte sem áudio ou falha no upload não invalida o HLS.
            this.warn(`Áudio não enviado: ${(error as Error).message}`);
            return false;
        }
    }

    private async buildStoryboard(
        job: PackageJob,
        storyboardSource: string,
        jobDir: string,
        meta: VideoMeta,
    ): Promise<StoryboardParams | false> {
        const local = join(jobDir, 'storyboard.jpg');
        try {
            const params = await this.storyboard.generate(storyboardSource, local, meta);
            await this.storage.uploadFile(local, job.storyboardKey);
            return params;
        } catch (error) {
            // Storyboard é enfeite de scrubbing: a ausência não invalida o HLS.
            this.warn(`Storyboard não gerado: ${(error as Error).message}`);
            return false;
        }
    }

    private hashFile(path: string): Promise<string> {
        return new Promise((resolve, reject) => {
            const hash = createHash('md5');
            const stream = createReadStream(path);
            stream.on('data', (chunk) => hash.update(chunk));
            stream.on('error', reject);
            stream.on('end', () => resolve(hash.digest('hex')));
        });
    }

    private workRoot(): string {
        return settings.workDir !== '' ? settings.workDir : join(tmpdir(), 'hls-jobs');
    }
}

export const packageQueue = new PackageQueueService();
