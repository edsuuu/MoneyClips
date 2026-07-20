import { createHash } from 'node:crypto';
import { randomUUID } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';
import { NotAVideoError } from '@/Exceptions/NotAVideoError';
import { S3Storage } from '@/Services/S3Storage';
import { HLSPackager } from '@/Services/Video/HLSPackager';
import { LadderBuilder } from '@/Services/Video/LadderBuilder';
import { Probe } from '@/Services/Video/Probe';
import { WebhookService } from '@/Services/WebhookService';

interface PackageJob {
    uuid: string;
    videoKey: string;
    outputPrefix: string;
    webhookUrl: string;
}

/**
 * ffmpeg é pesado e um empacotamento longo monopoliza CPU/GPU: a fila serializa
 * as execuções numa promise-chain (1 por vez), como no reencode.
 */
export class PackageQueueService extends Logger {
    private static readonly PROGRESS_THROTTLE_MS = 10_000;

    private chain: Promise<unknown> = Promise.resolve();
    private pending = 0;

    public constructor(
        private readonly storage: S3Storage = new S3Storage(),
        private readonly probe: Probe = new Probe(),
        private readonly ladderBuilder: LadderBuilder = new LadderBuilder(),
        private readonly packager: HLSPackager = new HLSPackager(),
        private readonly webhooks: WebhookService = new WebhookService(),
    ) {
        super();
    }

    public size(): number {
        return this.pending;
    }

    public enqueue(input: Omit<PackageJob, 'uuid'>): string {
        const job: PackageJob = { uuid: randomUUID(), ...input };
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

    private async process(job: PackageJob): Promise<void> {
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

            let lastReport = 0;
            const codec = await this.packager.package(
                sourcePath,
                outputDir,
                ladder,
                meta,
                remux,
                (percent) => {
                    const now = Date.now();
                    if (now - lastReport >= PackageQueueService.PROGRESS_THROTTLE_MS) {
                        lastReport = now;
                        this.webhooks.sendProgress(job.webhookUrl, job.uuid, percent);
                    }
                },
            );
            this.info(`Encode: ${codec}`);

            const poster = await this.extractPoster(sourcePath, outputDir, meta.durationSeconds);
            const hash = await this.hashFile(sourcePath);
            await this.storage.uploadDirectory(outputDir, job.outputPrefix);

            await this.webhooks.send(job.webhookUrl, {
                uuid: job.uuid,
                status: 'done',
                duration_seconds: meta.durationSeconds,
                width: meta.width,
                height: meta.height,
                hash,
                renditions: ladder.map((rendition) => rendition.name),
                poster,
            });

            this.info(`Empacotamento concluído: ${job.uuid}`);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.error(`Empacotamento falhou (${job.uuid}): ${message}`);

            await this.webhooks.send(job.webhookUrl, {
                uuid: job.uuid,
                status: error instanceof NotAVideoError ? 'rejected' : 'failed',
                error: message,
            });
        } finally {
            await rm(jobDir, { recursive: true, force: true }).catch(() => undefined);
        }
    }

    private async extractPoster(
        sourcePath: string,
        outputDir: string,
        durationSeconds: number,
    ): Promise<boolean> {
        try {
            await this.packager.extractPoster(
                sourcePath,
                join(outputDir, 'poster.jpg'),
                durationSeconds,
            );
            return true;
        } catch (error) {
            // Poster é enfeite: a ausência dele não invalida o empacotamento.
            this.warn(`Poster não extraído: ${(error as Error).message}`);
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
