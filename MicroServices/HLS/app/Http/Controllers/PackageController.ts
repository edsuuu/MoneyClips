import type { Request, Response } from 'express';

import { ValidationError } from '@/Exceptions/ValidationError';
import { PackageQueueService } from '@/Services/PackageQueueService';

interface CreatePackageRequest {
    video_key?: unknown;
    output_prefix?: unknown;
    webhook_url?: unknown;
}

export class PackageController {
    public constructor(private readonly queue: PackageQueueService) {}

    /**
     * ASSÍNCRONO: valida, enfileira e responde 202 {uuid} na hora. O
     * empacotamento roda em background e o desfecho vai pro Laravel na
     * webhook_url: {uuid, status: done|failed|rejected|progress, ...}.
     */
    public create(req: Request, res: Response): void {
        const body = (req.body ?? {}) as CreatePackageRequest;

        const videoKey = typeof body.video_key === 'string' ? body.video_key.trim() : '';
        const outputPrefix =
            typeof body.output_prefix === 'string' ? body.output_prefix.trim() : '';
        const webhookUrl = typeof body.webhook_url === 'string' ? body.webhook_url.trim() : '';

        const errors: Record<string, string> = {};

        if (videoKey === '') {
            errors['video_key'] = 'video_key obrigatória: a chave da fonte no storage.';
        }

        if (outputPrefix === '') {
            errors['output_prefix'] = 'output_prefix obrigatório: o prefixo de saída no storage.';
        }

        if (!/^https?:\/\//.test(webhookUrl)) {
            errors['webhook_url'] =
                'webhook_url obrigatória (http/https): o desfecho é assíncrono e chega por webhook.';
        }

        if (Object.keys(errors).length > 0) {
            throw new ValidationError(errors);
        }

        const uuid = this.queue.enqueue({ videoKey, outputPrefix, webhookUrl });

        res.status(202).json({ uuid });
    }
}
