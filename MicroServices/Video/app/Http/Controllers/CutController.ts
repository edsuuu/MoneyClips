import type { Request, Response } from 'express';

import { ValidationError } from '@/Exceptions/ValidationError';
import { CutQueueService } from '@/Services/CutQueueService';

interface CreateCutRequest {
    cut_uuid?: unknown;
    video_key?: unknown;
    start_seconds?: unknown;
    end_seconds?: unknown;
    clip_key?: unknown;
    audio_key?: unknown;
    webhook_url?: unknown;
}

export class CutController {
    public constructor(private readonly queue: CutQueueService) {}

    /**
     * ASSÍNCRONO: valida, enfileira e responde 202 {uuid} na hora. O corte roda
     * em background e o desfecho vai pro Laravel na webhook_url:
     * {uuid, cut_uuid, status: done|failed, ...}. O Laravel decide os paths de
     * destino — o serviço só escreve neles.
     */
    public create(req: Request, res: Response): void {
        const body = (req.body ?? {}) as CreateCutRequest;

        const cutUuid = CutController.str(body.cut_uuid);
        const videoKey = CutController.str(body.video_key);
        const startSeconds = CutController.num(body.start_seconds);
        const endSeconds = CutController.num(body.end_seconds);
        const clipKey = CutController.str(body.clip_key);
        const audioKey = CutController.str(body.audio_key);
        const webhookUrl = CutController.str(body.webhook_url);

        const errors: Record<string, string> = {};

        if (cutUuid === '') {
            errors['cut_uuid'] = 'cut_uuid obrigatório: como o Laravel acha o corte.';
        }

        if (videoKey === '') {
            errors['video_key'] = 'video_key obrigatória: a chave da fonte no storage.';
        }

        if (!Number.isFinite(startSeconds) || startSeconds < 0) {
            errors['start_seconds'] = 'start_seconds obrigatório: número >= 0.';
        }

        if (!Number.isFinite(endSeconds) || endSeconds <= startSeconds) {
            errors['end_seconds'] = 'end_seconds obrigatório: número maior que start_seconds.';
        }

        if (clipKey === '') {
            errors['clip_key'] = 'clip_key obrigatória: onde gravar o clip cortado.';
        }

        if (audioKey === '') {
            errors['audio_key'] = 'audio_key obrigatória: onde gravar o áudio do clip.';
        }

        if (!/^https?:\/\//.test(webhookUrl)) {
            errors['webhook_url'] =
                'webhook_url obrigatória (http/https): o desfecho é assíncrono e chega por webhook.';
        }

        if (Object.keys(errors).length > 0) {
            throw new ValidationError(errors);
        }

        const uuid = this.queue.enqueue({
            cutUuid,
            videoKey,
            startSeconds,
            endSeconds,
            clipKey,
            audioKey,
            webhookUrl,
        });

        res.status(202).json({ uuid });
    }

    private static str(value: unknown): string {
        return typeof value === 'string' ? value.trim() : '';
    }

    private static num(value: unknown): number {
        return typeof value === 'number' && Number.isFinite(value) ? value : Number.NaN;
    }
}
