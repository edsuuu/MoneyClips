import type { Request, Response } from 'express';

import { ValidationError } from '@/Exceptions/ValidationError';
import { PackageQueueService } from '@/Services/PackageQueueService';

interface CreatePackageRequest {
    video_uuid?: unknown;
    video_key?: unknown;
    hls_prefix?: unknown;
    poster_key?: unknown;
    audio_key?: unknown;
    storyboard_key?: unknown;
    webhook_url?: unknown;
}

export class PackageController {
    public constructor(private readonly queue: PackageQueueService) {}

    /**
     * ASSÍNCRONO: valida, enfileira e responde 202 {uuid} na hora. O
     * empacotamento roda em background e o desfecho vai pro Laravel na
     * webhook_url: {uuid, video_uuid, status: done|failed|rejected|progress, ...}.
     * O Laravel decide TODOS os paths de destino — o serviço só escreve neles.
     */
    public create(req: Request, res: Response): void {
        const body = (req.body ?? {}) as CreatePackageRequest;

        const videoUuid = PackageController.str(body.video_uuid);
        const videoKey = PackageController.str(body.video_key);
        const hlsPrefix = PackageController.str(body.hls_prefix);
        const posterKey = PackageController.str(body.poster_key);
        const audioKey = PackageController.str(body.audio_key);
        const storyboardKey = PackageController.str(body.storyboard_key);
        const webhookUrl = PackageController.str(body.webhook_url);

        const errors: Record<string, string> = {};

        if (videoUuid === '') {
            errors['video_uuid'] = 'video_uuid obrigatório: como o Laravel acha o vídeo.';
        }

        if (videoKey === '') {
            errors['video_key'] = 'video_key obrigatória: a chave da fonte no storage.';
        }

        if (hlsPrefix === '') {
            errors['hls_prefix'] = 'hls_prefix obrigatório: o prefixo de saída do HLS no storage.';
        }

        if (posterKey === '') {
            errors['poster_key'] = 'poster_key obrigatória: onde gravar o poster.';
        }

        if (audioKey === '') {
            errors['audio_key'] = 'audio_key obrigatória: onde gravar o áudio.';
        }

        if (storyboardKey === '') {
            errors['storyboard_key'] = 'storyboard_key obrigatória: onde gravar o storyboard.';
        }

        if (!/^https?:\/\//.test(webhookUrl)) {
            errors['webhook_url'] =
                'webhook_url obrigatória (http/https): o desfecho é assíncrono e chega por webhook.';
        }

        if (Object.keys(errors).length > 0) {
            throw new ValidationError(errors);
        }

        const uuid = this.queue.enqueue({
            videoUuid,
            videoKey,
            hlsPrefix,
            posterKey,
            audioKey,
            storyboardKey,
            webhookUrl,
        });

        res.status(202).json({ uuid });
    }

    private static str(value: unknown): string {
        return typeof value === 'string' ? value.trim() : '';
    }
}
