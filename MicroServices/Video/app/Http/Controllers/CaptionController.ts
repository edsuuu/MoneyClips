/**
 * Contrato do antigo AutoCaption, preservado byte a byte para o Laravel não
 * mudar: POST /videos (multipart) → 202 {uuid}; o desfecho vai por webhook e o
 * output é baixado em GET /videos/{uuid}/output/{variant}.
 */

import type { Request, Response } from 'express';
import { randomUUID } from 'node:crypto';
import { rename } from 'node:fs/promises';
import { extname } from 'node:path';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';
import { ValidationError } from '@/Exceptions/ValidationError';
import { ALL_VARIANTS, CaptionOptionsData } from '@/Services/Caption/CaptionOptionsData';
import { CaptionQueueService } from '@/Services/Caption/CaptionQueueService';
import type { Transcript } from '@/Services/Caption/SubtitleBuilder';
import { VideoStore } from '@/Services/Caption/VideoStore';

const SOURCE_EXTENSIONS = ['.mp4', '.mov', '.mkv', '.webm', '.avi', '.m4v'];

export class CaptionController extends Logger {
    public constructor(
        private readonly queue: CaptionQueueService,
        private readonly store: VideoStore = new VideoStore(),
    ) {
        super();
    }

    public async create(req: Request, res: Response): Promise<void> {
        const uploaded = req.file;

        if (uploaded === undefined) {
            throw new ValidationError({ file: 'campo "file" (arquivo) é obrigatório.' });
        }

        const extension = extname(uploaded.originalname).toLowerCase();

        if (!SOURCE_EXTENSIONS.includes(extension)) {
            throw new ValidationError({
                file: `extensão não suportada: ${extension || '(sem extensão)'}.`,
            });
        }

        const body = req.body as Record<string, unknown>;
        const variants = this.parseVariants(body['variants']);

        if (variants.length === 0) {
            throw new ValidationError({ variants: 'nenhuma variante válida informada.' });
        }

        const uuid = randomUUID();
        await this.store.ensureDir(uuid);
        await rename(uploaded.path, this.store.sourcePath(uuid, extension));

        const options = new CaptionOptionsData({
            variants,
            captionPosition:
                this.asString(body['caption_position']) === 'inside' ? 'inside' : 'below',
            channelName: this.asString(body['channel_name']),
            channelHandle: this.asString(body['channel_handle']),
            watermarkText: this.asString(body['watermark_text']) || settings.watermarkText,
            withCaptions: this.asBool(body['with_captions']),
            subtitleOffset: this.asOptionalFloat(body['subtitle_offset']),
        });

        await this.store.writeStatus(uuid, {
            uuid,
            status: 'processing',
            step: 'queued',
            updated_at: new Date().toISOString(),
            webhook_url: this.asString(body['webhook_url']),
            options: {
                variants: options.variants,
                caption_position: options.captionPosition,
                channel_name: options.channelName,
                channel_handle: options.channelHandle,
                watermark_text: options.watermarkText,
                with_captions: options.withCaptions,
                subtitle_offset: options.subtitleOffset,
            },
        });

        this.queue.enqueue(uuid, options);
        this.info(`[Caption] Job ${uuid} enfileirado (${options.variants.join(', ')}).`);

        res.status(202).json({ uuid, status: 'processing' });
    }

    public async transcription(req: Request, res: Response): Promise<void> {
        if (!this.authorized(req)) {
            res.status(401).json({ detail: 'não autorizado' });

            return;
        }

        const uuid = String(req.params['uuid']);

        if ((await this.store.readStatus(uuid)) === null) {
            res.status(404).json({ detail: 'job não encontrado' });

            return;
        }

        const body = req.body as Record<string, unknown>;

        if (this.asString(body['status']) === 'done') {
            this.queue.enqueueResume(uuid, body['transcript'] as Transcript);
        } else {
            this.queue.enqueueResumeFailure(
                uuid,
                this.asString(body['error']) || 'transcrição falhou',
            );
        }

        res.status(202).json({ status: 'accepted' });
    }

    public async index(_req: Request, res: Response): Promise<void> {
        const uuids = await this.store.listVideos();
        const videos = await Promise.all(
            uuids.map(
                async (uuid) => (await this.store.readStatus(uuid)) ?? { uuid, status: 'unknown' },
            ),
        );

        res.json({ videos });
    }

    public async show(req: Request, res: Response): Promise<void> {
        const status = await this.store.readStatus(String(req.params['uuid']));

        if (status === null) {
            res.status(404).json({ detail: 'job não encontrado' });

            return;
        }

        res.json(status);
    }

    public async output(req: Request, res: Response): Promise<void> {
        const uuid = String(req.params['uuid']);
        const variant = String(req.params['variant']);
        const path = this.store.outputPath(uuid, variant);

        if (path === null) {
            res.status(404).json({ detail: `variante desconhecida: ${variant}` });

            return;
        }

        const status = await this.store.readStatus(uuid);

        if (status === null) {
            res.status(404).json({ detail: 'job não encontrado' });

            return;
        }

        res.download(path, `${uuid}_${variant}.mp4`, (error) => {
            if (error && !res.headersSent) {
                res.status(404).json({ detail: 'output ainda não disponível' });
            }
        });
    }

    private parseVariants(raw: unknown): string[] {
        if (typeof raw !== 'string' || raw.trim() === '') {
            return [...ALL_VARIANTS];
        }

        return raw
            .split(',')
            .map((variant) => variant.trim())
            .filter((variant) => ALL_VARIANTS.includes(variant));
    }

    private authorized(req: Request): boolean {
        const expected = settings.observabilityToken;

        return expected === '' || req.header('X-Observability-Token') === expected;
    }

    private asString(raw: unknown): string {
        return typeof raw === 'string' ? raw : '';
    }

    private asOptionalFloat(raw: unknown): number | null {
        if (typeof raw !== 'string' || raw.trim() === '') {
            return null;
        }

        const parsed = Number(raw);

        return Number.isFinite(parsed) ? parsed : null;
    }

    private asBool(raw: unknown): boolean {
        if (typeof raw !== 'string' || raw.trim() === '') {
            return true;
        }

        return ['1', 'true', 'yes', 'on', 'sim'].includes(raw.trim().toLowerCase());
    }
}
