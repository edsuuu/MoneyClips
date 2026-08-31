import type { Request, Response } from 'express';

import { ValidationError } from '@/Exceptions/ValidationError';
import type { Transcript } from '@/Services/Caption/SubtitleBuilder';
import type { ReframeKeyframe } from '@/Services/Reframe/ReframeFilterBuilder';
import { ReframeQueueService } from '@/Services/Reframe/ReframeQueueService';

const HEX_COLOR = /^#[0-9a-f]{6}$/iu;

interface CreateReframeRequest {
    edit_uuid?: unknown;
    source_key?: unknown;
    output_key?: unknown;
    source?: unknown;
    keyframes?: unknown;
    settings?: unknown;
    transcript?: unknown;
    webhook_url?: unknown;
}

export class ReframeController {
    public constructor(private readonly queue: ReframeQueueService) {}

    /**
     * ASSÍNCRONO: valida, enfileira e responde 202 {uuid} na hora. O render
     * roda em background e o desfecho vai pro Laravel na webhook_url:
     * {uuid, edit_uuid, status: done|failed, error?}. O Laravel decide os
     * paths de destino — o serviço só escreve neles. Payload interno já
     * sanitizado pelo Laravel; aqui só a validação estrutural.
     */
    public create(req: Request, res: Response): void {
        const body = (req.body ?? {}) as CreateReframeRequest;

        const editUuid = ReframeController.str(body.edit_uuid);
        const sourceKey = ReframeController.str(body.source_key);
        const outputKey = ReframeController.str(body.output_key);
        const webhookUrl = ReframeController.str(body.webhook_url);
        const source = (body.source ?? {}) as Record<string, unknown>;
        const duration = Number(source['duration'] ?? 0);
        const keyframes = Array.isArray(body.keyframes)
            ? (body.keyframes as ReframeKeyframe[])
            : [];
        const settings = (body.settings ?? {}) as Record<string, unknown>;

        const errors: Record<string, string> = {};

        if (editUuid === '') {
            errors['edit_uuid'] = 'edit_uuid obrigatório: como o Laravel acha a edição.';
        }

        if (sourceKey === '') {
            errors['source_key'] = 'source_key obrigatória: a chave do clip no storage.';
        }

        if (outputKey === '') {
            errors['output_key'] = 'output_key obrigatória: onde escrever o resultado.';
        }

        if (webhookUrl === '') {
            errors['webhook_url'] = 'webhook_url obrigatória: onde avisar o desfecho.';
        }

        if (keyframes.length === 0) {
            errors['keyframes'] = 'keyframes obrigatórios: pelo menos um.';
        }

        if (Object.keys(errors).length > 0) {
            throw new ValidationError(errors);
        }

        const uuid = this.queue.enqueue({
            editUuid,
            sourceKey,
            outputKey,
            duration: Number.isFinite(duration) ? duration : 0,
            keyframes,
            settings: {
                background: ReframeController.str(settings['background']) || '#000000',
                captions: settings['captions'] === true,
                captionColor: ReframeController.str(settings['captionColor']) || '#ffffff',
                captionCase: ReframeController.str(settings['captionCase']) || 'sentence',
                speakerColors: ReframeController.speakerColors(settings['speakerColors']),
            },
            transcript:
                typeof body.transcript === 'object' && body.transcript !== null
                    ? (body.transcript as Transcript)
                    : null,
            webhookUrl,
        });

        res.status(202).json({ uuid });
    }

    private static str(value: unknown): string {
        return typeof value === 'string' ? value.trim() : '';
    }

    /**
     * Mapa de cor por locutor: só entra o par cuja cor é #rrggbb. O que vier
     * fora do formato é descartado em silêncio (a legenda cai na cor padrão) —
     * derrubar o render inteiro por causa de uma cor é pior que ignorá-la.
     */
    private static speakerColors(value: unknown): Record<string, string> {
        if (typeof value !== 'object' || value === null || Array.isArray(value)) {
            return {};
        }

        const colors: Record<string, string> = {};

        for (const [speaker, color] of Object.entries(value as Record<string, unknown>)) {
            if (typeof color === 'string' && HEX_COLOR.test(color.trim())) {
                colors[speaker] = color.trim();
            }
        }

        return colors;
    }
}
