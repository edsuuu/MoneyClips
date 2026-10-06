import { cp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';

import { settings } from '@/Config/Env';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';
import { SubtitleBuilder, type Transcript } from '@/Services/Caption/SubtitleBuilder';
import {
    type CaptionPreset,
    LiteralSubtitleBuilder,
    type ReframeCaption,
} from '@/Services/Reframe/LiteralSubtitleBuilder';
import {
    ReframeFilterBuilder,
    type ReframeKeyframe,
    type ReframeOverlay,
    type ReframeRenderSettings,
    type ReframeSfx,
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
    keyframes: ReframeKeyframe[];
    settings: ReframeRenderSettings;
    transcript: Transcript | null;
    captions: ReframeCaption[] | null;
    captionPreset: CaptionPreset | null;
    watermark: string;
    webhookUrl: string;
    overlays: ReframeOverlay[];
    sfx: ReframeSfx[];
}

const VERTICAL_FONT_SCALE = 1.5;
const FONTS_DIR = 'assets/fonts';

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
        private readonly literalSubtitles: LiteralSubtitleBuilder = new LiteralSubtitleBuilder(),
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
            await this.downloadAssets(job, jobDir);

            const meta = await this.probe.read(join(jobDir, 'source'));

            if (!(meta.exactDurationSeconds > 0)) {
                throw new Error('ffprobe não retornou a duração do clip.');
            }

            if (job.sfx.length > 0 && !meta.hasAudio) {
                throw new Error('sfx[] mistura sobre a voz, e o clip não tem faixa de áudio.');
            }

            const ass =
                job.captions === null
                    ? await this.buildSubtitles(job, jobDir)
                    : await this.buildLiteralSubtitles(
                          job,
                          job.captions,
                          jobDir,
                          meta.exactDurationSeconds,
                      );

            const filter = this.filters.build(
                job.keyframes,
                job.settings.background,
                meta.width,
                meta.height,
                meta.exactDurationSeconds,
                meta.fps,
                ass,
                job.overlays,
                job.sfx,
            );
            const audioMap = job.sfx.length === 0 ? '0:a?' : '[aout]';
            const audioCodec = job.sfx.length === 0 ? ['copy'] : ['aac', '-b:a', '192k'];

            await this.ffmpeg.runWithFallback(
                (encoderArgs) => [
                    '-hide_banner',
                    '-y',
                    '-i',
                    'source',
                    ...this.filters.inputArgs(job.overlays, job.sfx),
                    '-filter_complex',
                    filter,
                    '-map',
                    '[vout]',
                    '-map',
                    audioMap,
                    ...encoderArgs,
                    '-c:a',
                    ...audioCodec,
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
            await rm(jobDir, { recursive: true, force: true }).catch((error) =>
                this.warn(
                    `Falha ao limpar o diretório do job ${job.uuid}: ${(error as Error).message}`,
                ),
            );
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
                speakerColors: this.assSpeakerColors(job.settings.speakerColors),
                textTransform: this.assTransform(job.settings.captionCase),
            },
        );

        return 'subs.ass';
    }

    /**
     * O libass troca fonte que não acha por outra SEM erro: as Montserrat vão
     * junto no jobDir (fontsdir relativo, pelo mesmo motivo do `ass=` no
     * FfmpegRunner). O `cp` falha alto se o serviço subir fora da sua pasta.
     *
     * ponytail: o fontsdir viaja dentro da string do assFile pra não mexer no
     * ReframeFilterBuilder (PR11); quotar o assFile derruba a fonte calado.
     * Upgrade: virar parâmetro do build() depois do PR7/PR11.
     */
    private async buildLiteralSubtitles(
        job: ReframeJob,
        captions: ReframeCaption[],
        jobDir: string,
        duration: number,
    ): Promise<string> {
        await cp(FONTS_DIR, join(jobDir, 'fonts'), { recursive: true });
        await writeFile(
            join(jobDir, 'captions.ass'),
            this.literalSubtitles.render(captions, job.captionPreset, job.watermark, duration),
            'utf8',
        );

        return 'captions.ass:fontsdir=fonts';
    }

    /** #rrggbb → &H00BBGGRR (ordem invertida do .ass). Cor inválida cai no branco. */
    private assColor(hex: string): string {
        const match = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/iu.exec(hex.trim());

        if (match === null) {
            return '&H00FFFFFF';
        }

        return `&H00${match[3]!.toUpperCase()}${match[2]!.toUpperCase()}${match[1]!.toUpperCase()}`;
    }

    private assSpeakerColors(colors: Record<string, string>): Record<string, string> {
        return Object.fromEntries(
            Object.entries(colors).map(([speaker, hex]) => [speaker, this.assColor(hex)]),
        );
    }

    private assTransform(captionCase: string): 'upper' | 'lower' | 'none' {
        return ({ lower: 'lower', sentence: 'none' } as const)[captionCase] ?? 'upper';
    }

    /** A key vira caminho local: só é seguro porque o controller barra segmento `.`/`..` e prefixo fora de assets/. */
    private async downloadAssets(job: ReframeJob, jobDir: string): Promise<void> {
        const keys = new Set([
            ...job.overlays.map((overlay) => overlay.key),
            ...job.sfx.map((item) => item.key),
        ]);

        for (const key of keys) {
            await mkdir(dirname(join(jobDir, key)), { recursive: true });
            await this.storage.download(key, join(jobDir, key));
        }
    }

    private workRoot(): string {
        return settings.workDir !== '' ? settings.workDir : join(tmpdir(), 'reframe-jobs');
    }
}

export const reframeQueue = new ReframeQueueService();
