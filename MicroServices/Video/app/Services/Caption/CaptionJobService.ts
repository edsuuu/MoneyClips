/**
 * Orquestra um job de legenda/template em duas fases quando há legenda:
 *
 *   fase 1 (process)  → áudio → submete a transcrição ao serviço Python e PARA.
 *   fase 2 (resume)   → chega o webhook do transcritor → variantes → status →
 *                       webhook do Laravel.
 *
 * Sem legenda, `process` faz tudo de uma vez (não há transcrição a esperar).
 * O webhook pro Laravel sai em `render`/`fail`: sucesso e falha avisam do mesmo
 * jeito, senão um erro deixa o `processing_jobs` pendurado pra sempre.
 */

import { writeFile } from 'node:fs/promises';

import { settings } from '@/Config/Env';
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

            if (options.withCaptions) {
                await this.setStatus(uuid, 'processing', 'extracting_audio');
                const audioPath = await this.audio.extract(source, this.store.audioPath(uuid));

                await this.setStatus(uuid, 'processing', 'transcribing');
                await this.transcriber.submit(uuid, audioPath, this.callbackUrl(uuid));

                return;
            }

            await this.render(uuid, options, source, null);
        } catch (error) {
            await this.fail(uuid, error);
        }
    }

    public async resume(uuid: string, transcript: Transcript): Promise<void> {
        try {
            const source = await this.store.findSource(uuid);

            if (source === null) {
                throw new Error('source do vídeo não encontrado');
            }

            await writeFile(
                this.store.transcriptPath(uuid),
                JSON.stringify(transcript, null, 2),
                'utf8',
            );

            await this.render(uuid, await this.loadOptions(uuid), source, transcript);
        } catch (error) {
            await this.fail(uuid, error);
        }
    }

    public async failFromTranscriber(uuid: string, message: string): Promise<void> {
        await this.fail(uuid, new Error(`transcrição falhou: ${message}`));
    }

    private async render(
        uuid: string,
        options: CaptionOptionsData,
        source: string,
        transcript: Transcript | null,
    ): Promise<void> {
        const meta = await this.probe.read(source);
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

        await this.notify(uuid);
    }

    private async fail(uuid: string, error: unknown): Promise<void> {
        const message = error instanceof Error ? error.message : String(error);
        this.error(`[Caption] [${uuid}] pipeline falhou: ${message}`);

        await this.setStatus(uuid, 'failed', 'error', {
            error: message,
            files: this.store.files(uuid),
        });

        await this.notify(uuid);
    }

    private callbackUrl(uuid: string): string {
        return `${settings.selfBaseUrl.replace(/\/+$/u, '')}/videos/${uuid}/transcription`;
    }

    private async loadOptions(uuid: string): Promise<CaptionOptionsData> {
        const raw = (await this.store.readStatus(uuid))?.options ?? {};

        return new CaptionOptionsData({
            variants: Array.isArray(raw['variants']) ? (raw['variants'] as string[]) : [],
            captionPosition: raw['caption_position'] === 'inside' ? 'inside' : 'below',
            channelName: this.asString(raw['channel_name']),
            channelHandle: this.asString(raw['channel_handle']),
            watermarkText: this.asString(raw['watermark_text']),
            withCaptions: raw['with_captions'] !== false,
            subtitleOffset:
                typeof raw['subtitle_offset'] === 'number' ? raw['subtitle_offset'] : null,
        });
    }

    private asString(raw: unknown): string {
        return typeof raw === 'string' ? raw : '';
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
