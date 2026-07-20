/**
 * Orquestra um job de legenda/template: áudio → transcrição (Python) →
 * variantes → status → webhook.
 *
 * O webhook sai no `finally`: sucesso e falha avisam o Laravel do mesmo jeito,
 * senão um erro deixa o `processing_jobs` pendurado pra sempre.
 */

import { writeFile } from 'node:fs/promises';

import { Logger } from '@/Config/Logger';
import { AudioExtractor } from '@/Services/Caption/AudioExtractor';
import { CaptionOptionsData } from '@/Services/Caption/CaptionOptionsData';
import type { Transcript } from '@/Services/Caption/SubtitleBuilder';
import { TranscriberClient } from '@/Services/Caption/TranscriberClient';
import { VariantRenderer } from '@/Services/Caption/VariantRenderer';
import { VideoStore } from '@/Services/Caption/VideoStore';
import { Probe } from '@/Services/Video/Probe';
import { WebhookService } from '@/Services/WebhookService';

export class CaptionJobService extends Logger {
    public constructor(
        private readonly store: VideoStore = new VideoStore(),
        private readonly audio: AudioExtractor = new AudioExtractor(),
        private readonly transcriber: TranscriberClient = new TranscriberClient(),
        private readonly renderer: VariantRenderer = new VariantRenderer(),
        private readonly probe: Probe = new Probe(),
        private readonly webhooks: WebhookService = new WebhookService(),
    ) {
        super();
    }

    public async process(uuid: string, options: CaptionOptionsData): Promise<void> {
        try {
            const source = await this.store.findSource(uuid);

            if (source === null) {
                throw new Error('source do vídeo não encontrado');
            }

            const meta = await this.probe.read(source);
            let transcript: Transcript | null = null;

            if (options.withCaptions) {
                await this.setStatus(uuid, 'processing', 'extracting_audio');
                const audioPath = await this.audio.extract(source, this.store.audioPath(uuid));

                await this.setStatus(uuid, 'processing', 'transcribing');
                transcript = await this.transcriber.transcribe(audioPath);
                await writeFile(
                    this.store.transcriptPath(uuid),
                    JSON.stringify(transcript, null, 2),
                    'utf8',
                );
            }

            await this.setStatus(uuid, 'processing', 'rendering_variants');

            const timings = await this.renderer.renderAll({
                uuid,
                source,
                sourceWidth: meta.width,
                sourceHeight: meta.height,
                durationSeconds: meta.durationSeconds,
                transcript,
                options,
            });

            await this.setStatus(uuid, 'done', 'completed', {
                files: this.store.files(uuid),
                variant_seconds: timings,
                error: null,
            });
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.error(`[Caption] [${uuid}] pipeline falhou: ${message}`);
            await this.setStatus(uuid, 'failed', 'error', {
                error: message,
                files: this.store.files(uuid),
            });
        } finally {
            await this.notify(uuid);
        }
    }

    private async setStatus(
        uuid: string,
        status: 'processing' | 'done' | 'failed',
        step: string,
        extra: Record<string, unknown> = {},
    ): Promise<void> {
        await this.store.writeStatus(uuid, {
            uuid,
            status,
            step,
            updated_at: new Date().toISOString(),
            ...extra,
        });
        this.info(`[Caption] [${uuid}] ${status} / ${step}`);
    }

    private async notify(uuid: string): Promise<void> {
        const status = await this.store.readStatus(uuid);
        const url = status?.webhook_url ?? '';

        if (url === '') {
            return;
        }

        await this.webhooks.send(url, {
            uuid,
            status: status?.status === 'done' ? 'done' : 'failed',
            ...(status?.error ? { error: status.error } : {}),
            files: status?.files ?? {},
        });
    }
}
