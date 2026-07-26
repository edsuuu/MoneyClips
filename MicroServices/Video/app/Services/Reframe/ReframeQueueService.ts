import { mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { settings } from '@/Config/Env';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';
import { SubtitleBuilder, type Transcript } from '@/Services/Caption/SubtitleBuilder';
import {
    ReframeFilterBuilder,
    type ReframeKeyframe,
    type ReframeRenderSettings,
} from '@/Services/Reframe/ReframeFilterBuilder';
import { S3Storage } from '@/Services/S3Storage';
import { SerialQueueService } from '@/Services/SerialQueueService';
import { Probe } from '@/Services/Video/Probe';
import { WebhookService } from '@/Services/WebhookService';

export interface ReframeJob {
    uuid: string;
    editUuid: string;
    sourceKey: string;
    outputKey: string;
    duration: number;
    keyframes: ReframeKeyframe[];
    settings: ReframeRenderSettings;
    transcript: Transcript | null;
    webhookUrl: string;
}

const VERTICAL_FONT_SCALE = 1.5;

/**
 * Renderiza o corte editado (reframe do /editor-de-video) em 1080x1920: baixa
 * o clip do MinIO, aplica o filtergraph do ReframeFilterBuilder e sobe o
 * resultado. Fila serial em promise-chain, como as demais — ffmpeg monopoliza
 * CPU/GPU. Segue o padrão do /cut: o Laravel manda as chaves, o serviço só
 * escreve nelas.
 */
export class ReframeQueueService extends SerialQueueService<ReframeJob> {
    public constructor(
        private readonly storage: S3Storage = new S3Storage(),
        private readonly probe: Probe = new Probe(),
        private readonly ffmpeg: FfmpegRunner = new FfmpegRunner(),
        private readonly filters: ReframeFilterBuilder = new ReframeFilterBuilder(),
        private readonly subtitles: SubtitleBuilder = new SubtitleBuilder(),
        private readonly webhooks: WebhookService = new WebhookService(),
    ) {
        super();
    }

    protected async process(job: ReframeJob): Promise<void> {
        const jobDir = join(this.workRoot(), job.uuid);

        this.info('='.repeat(50));
        this.info(`Reframe solicitado: ${job.editUuid} (${job.sourceKey} → ${job.outputKey})`);

        try {
            await mkdir(jobDir, { recursive: true });
            await this.storage.download(job.sourceKey, join(jobDir, 'source'));

            const meta = await this.probe.read(join(jobDir, 'source'));
            const duration = job.duration > 0 ? job.duration : meta.durationSeconds;
            const ass = await this.buildSubtitles(job, jobDir);

            const filter = this.filters.build(
                job.keyframes,
                job.settings.background,
                meta.width,
                meta.height,
                duration,
                meta.fps,
                ass,
            );

            await this.ffmpeg.runWithFallback(
                (encoderArgs) => [
                    '-hide_banner',
                    '-y',
                    '-i',
                    'source',
                    '-filter_complex',
                    filter,
                    '-map',
                    '[vout]',
                    '-map',
                    '0:a?',
                    ...encoderArgs,
                    '-c:a',
                    'copy',
                    '-movflags',
                    '+faststart',
                    'out.mp4',
                ],
                jobDir,
                'reframe',
            );

            await this.storage.uploadFile(join(jobDir, 'out.mp4'), job.outputKey);

            await this.webhooks.send(job.webhookUrl, {
                uuid: job.uuid,
                edit_uuid: job.editUuid,
                status: 'done',
            });

            this.info(`Reframe concluído: ${job.editUuid}`);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.error(`Reframe falhou (${job.editUuid}): ${message}`);

            await this.webhooks.send(job.webhookUrl, {
                uuid: job.uuid,
                edit_uuid: job.editUuid,
                status: 'failed',
                error: message,
            });
        } finally {
            await rm(jobDir, { recursive: true, force: true }).catch(() => undefined);
        }
    }

    private async buildSubtitles(job: ReframeJob, jobDir: string): Promise<string | null> {
        if (!job.settings.captions || job.transcript === null) {
            return null;
        }

        await this.subtitles.build(
            job.transcript,
            join(jobDir, 'subs.srt'),
            join(jobDir, 'subs.ass'),
            {
                width: 1080,
                height: 1920,
                fontScale: VERTICAL_FONT_SCALE,
                primaryColor: this.assColor(job.settings.captionColor),
                textTransform: this.assTransform(job.settings.captionCase),
            },
        );

        return 'subs.ass';
    }

    /** #rrggbb → &H00BBGGRR (ordem invertida do .ass). Cor inválida cai no branco. */
    private assColor(hex: string): string {
        const match = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/iu.exec(hex.trim());

        if (match === null) {
            return '&H00FFFFFF';
        }

        return `&H00${match[3]!.toUpperCase()}${match[2]!.toUpperCase()}${match[1]!.toUpperCase()}`;
    }

    private assTransform(captionCase: string): 'upper' | 'lower' | 'none' {
        return ({ lower: 'lower', sentence: 'none' } as const)[captionCase] ?? 'upper';
    }

    private workRoot(): string {
        return settings.workDir !== '' ? settings.workDir : join(tmpdir(), 'reframe-jobs');
    }
}

export const reframeQueue = new ReframeQueueService();
