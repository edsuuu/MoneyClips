import type { Request, Response } from 'express';

import { ValidationError } from '@/Exceptions/ValidationError';
import type { Transcript } from '@/Services/Caption/SubtitleBuilder';
import {
    CAPTION_POSITIONS,
    CAPTION_PRESETS,
    CAPTION_STYLES,
    type ReframeCaption,
} from '@/Services/Reframe/LiteralSubtitleBuilder';
import type { ReframeKeyframe } from '@/Services/Reframe/ReframeFilterBuilder';
import { ReframeQueueService } from '@/Services/Reframe/ReframeQueueService';

const HEX_COLOR = /^#[0-9a-f]{6}$/iu;

interface CreateReframeRequest {
    edit_uuid?: unknown;
    source_key?: unknown;
    output_key?: unknown;
    keyframes?: unknown;
    settings?: unknown;
    transcript?: unknown;
    captions?: unknown;
    caption_preset?: unknown;
    watermark?: unknown;
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
        const keyframes = Array.isArray(body.keyframes)
            ? (body.keyframes as ReframeKeyframe[])
            : [];
        const settings = (body.settings ?? {}) as Record<string, unknown>;
        const captionPreset = ReframeController.str(body.caption_preset);
        const preset = CAPTION_PRESETS.find((name) => name === captionPreset) ?? null;
        const watermark = ReframeController.str(body.watermark);

        const errors: Record<string, string> = {};
        const captions = ReframeController.captions(body.captions, errors);

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

        if (captionPreset !== '' && preset === null) {
            errors['caption_preset'] =
                `caption_preset inválido: use ${CAPTION_PRESETS.join(', ')}.`;
        }

        if ((captionPreset !== '' || watermark !== '') && captions === null) {
            errors['captions'] ??=
                'caption_preset e watermark só valem com captions[] (legenda literal).';
        }

        if (Object.keys(errors).length > 0) {
            throw new ValidationError(errors);
        }

        const uuid = this.queue.enqueue({
            editUuid,
            sourceKey,
            outputKey,
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
            captions,
            captionPreset: preset,
            watermark,
            webhookUrl,
        });

        res.status(202).json({ uuid });
    }

    private static str(value: unknown): string {
        return typeof value === 'string' ? value.trim() : '';
    }

    /**
     * Ausente = legenda karaokê de antes (null). Presente, cada bloco é
     * conferido aqui: erro vira 422 no enqueue, não falha no meio do render.
     */
    private static captions(
        value: unknown,
        errors: Record<string, string>,
    ): ReframeCaption[] | null {
        if (value === undefined || value === null) {
            return null;
        }

        if (!Array.isArray(value)) {
            errors['captions'] = 'captions precisa ser uma lista de blocos.';

            return null;
        }

        const captions: ReframeCaption[] = [];

        for (const [index, item] of value.entries()) {
            const block: Record<string, unknown> =
                typeof item === 'object' && item !== null ? item : {};
            const times: unknown[] = Array.isArray(block['t']) ? block['t'] : [];
            const [start, end] = times;
            const validTimes =
                times.length === 2 &&
                typeof start === 'number' &&
                typeof end === 'number' &&
                Number.isFinite(start) &&
                start < end &&
                Number.isFinite(end);
            const text = typeof block['text'] === 'string' ? block['text'] : '';
            const style = CAPTION_STYLES.find((name) => name === (block['style'] ?? 'speech'));
            const pos = CAPTION_POSITIONS.find((name) => name === (block['pos'] ?? 'bottom'));

            if (!validTimes) {
                errors[`captions.${String(index)}.t`] =
                    't precisa ser [início, fim] em segundos, com início < fim.';
            }

            if (text.trim() === '') {
                errors[`captions.${String(index)}.text`] = 'text obrigatório.';
            }

            if (style === undefined) {
                errors[`captions.${String(index)}.style`] =
                    `style inválido: use ${CAPTION_STYLES.join(', ')}.`;
            }

            if (pos === undefined) {
                errors[`captions.${String(index)}.pos`] =
                    `pos inválido: use ${CAPTION_POSITIONS.join(', ')}.`;
            }

            if (validTimes && style !== undefined && pos !== undefined) {
                captions.push({ t: [start, end], text, style, pos });
            }
        }

        return captions;
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
