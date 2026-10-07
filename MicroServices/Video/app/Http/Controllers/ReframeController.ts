import type { Request, Response } from 'express';

import { ValidationError } from '@/Exceptions/ValidationError';
import type { Transcript } from '@/Services/Caption/SubtitleBuilder';
import {
    CAPTION_POSITIONS,
    CAPTION_PRESETS,
    CAPTION_STYLES,
    type ReframeCaption,
} from '@/Services/Reframe/LiteralSubtitleBuilder';
import type {
    ReframeKeyframe,
    ReframeOverlay,
    ReframeOverlayKind,
    ReframeSfx,
} from '@/Services/Reframe/ReframeFilterBuilder';
import { ReframeQueueService } from '@/Services/Reframe/ReframeQueueService';
import type { TimeRange } from '@/Services/Reframe/Timeline';

const HEX_COLOR = /^#[0-9a-f]{6}$/iu;

const ASSET_KEY = /^assets(?:\/[\w-][\w.-]*)+$/u;

const VIDEO_KEY = /\.(mp4|mov|webm)$/iu;

const IMAGE_KEY = /\.(png|jpe?g|webp|gif|apng)$/iu;

const OVERLAY_KINDS: ReframeOverlayKind[] = ['card', 'small', 'emoji', 'meme', 'meme_clip'];

const MAX_OVERLAYS = 40;

const MAX_SFX = 40;

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
    overlays?: unknown;
    sfx?: unknown;
    cuts?: unknown;
    dead_air?: unknown;
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

        const overlays = ReframeController.overlays(body.overlays, errors);
        const sfx = ReframeController.sfx(body.sfx, errors);
        const cuts = ReframeController.cuts(body.cuts, errors);
        const deadAir = body.dead_air === true;

        if (
            body.dead_air !== undefined &&
            body.dead_air !== null &&
            typeof body.dead_air !== 'boolean'
        ) {
            errors['dead_air'] = 'dead_air é booleano.';
        }

        if ((cuts !== null || deadAir) && captions === null && settings['captions'] === true) {
            errors['captions'] ??=
                'cuts/dead_air mudam o tempo do clip e a legenda karaokê não acompanha: mande captions[].';
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
            overlays,
            sfx,
            cuts,
            deadAir,
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

    /**
     * Figurinhas lidas do MinIO: key só em assets/ (a credencial do serviço não
     * deve ler outro prefixo) e kind casando com o arquivo — meme_clip é vídeo,
     * os demais são imagem. Qualquer item fora disso é 422 no enqueue, não
     * falha no meio do render.
     */
    private static overlays(value: unknown, errors: Record<string, string>): ReframeOverlay[] {
        const overlays: ReframeOverlay[] = [];

        ReframeController.items(value, 'overlays', MAX_OVERLAYS, errors).forEach((item, index) => {
            const field = `overlays.${String(index)}`;
            const key = ReframeController.str(item['key']);
            const kind = OVERLAY_KINDS.find((candidate) => candidate === item['kind']);
            const [start, end] = Array.isArray(item['t']) ? (item['t'] as unknown[]) : [];
            const pos = (item['pos'] ?? null) as Record<string, unknown> | null;

            if (!ASSET_KEY.test(key)) {
                errors[`${field}.key`] = 'key fora de assets/: o serviço só lê assets do MinIO.';
                return;
            }

            if (kind === undefined || !(kind === 'meme_clip' ? VIDEO_KEY : IMAGE_KEY).test(key)) {
                errors[`${field}.kind`] =
                    'kind card|small|emoji|meme pede imagem (png, jpg, webp, gif, apng); meme_clip pede vídeo (mp4, mov, webm).';
                return;
            }

            if (
                typeof start !== 'number' ||
                typeof end !== 'number' ||
                !Number.isFinite(end) ||
                start < 0 ||
                end <= start
            ) {
                errors[`${field}.t`] = 't é [a, b] em segundos, com 0 ≤ a < b.';
                return;
            }

            if (
                pos !== null &&
                (!ReframeController.unit(pos['x']) || !ReframeController.unit(pos['y']))
            ) {
                errors[`${field}.pos`] = 'pos é {x, y} normalizado entre 0 e 1.';
                return;
            }

            overlays.push({
                key,
                t: [start, end],
                kind,
                pos: pos === null ? null : { x: pos['x'] as number, y: pos['y'] as number },
            });
        });

        return overlays;
    }

    private static sfx(value: unknown, errors: Record<string, string>): ReframeSfx[] {
        const sfx: ReframeSfx[] = [];

        ReframeController.items(value, 'sfx', MAX_SFX, errors).forEach((item, index) => {
            const field = `sfx.${String(index)}`;
            const key = ReframeController.str(item['key']);
            const t = item['t'];
            const gainDb = item['gain_db'] ?? 0;

            if (!ASSET_KEY.test(key)) {
                errors[`${field}.key`] = 'key fora de assets/: o serviço só lê assets do MinIO.';
                return;
            }

            if (typeof t !== 'number' || !Number.isFinite(t) || t < 0) {
                errors[`${field}.t`] = 't em segundos, ≥ 0.';
                return;
            }

            if (typeof gainDb !== 'number' || gainDb < -60 || gainDb > 20) {
                errors[`${field}.gain_db`] = 'gain_db em dB, entre -60 e 20.';
                return;
            }

            sfx.push({ key, t, gainDb });
        });

        return sfx;
    }

    /**
     * Trechos a REMOVER, em segundos do clip, no formato do spec do Laravel:
     * ordenados e sem sobreposição (encostar pode). Ausente = sem jump cut;
     * `[]` liga o pipeline novo (concat a/v + loudnorm) sem cortar nada.
     */
    private static cuts(value: unknown, errors: Record<string, string>): TimeRange[] | null {
        if (value === undefined || value === null) {
            return null;
        }

        if (!Array.isArray(value)) {
            errors['cuts'] = 'cuts é uma lista de [início, fim] em segundos do clip.';
            return null;
        }

        const cuts: TimeRange[] = [];
        let previousEnd = 0;

        for (const [index, item] of value.entries()) {
            const [start, end] =
                Array.isArray(item) && item.length === 2 ? (item as unknown[]) : [];

            if (
                typeof start !== 'number' ||
                typeof end !== 'number' ||
                !Number.isFinite(start) ||
                !Number.isFinite(end) ||
                start < previousEnd ||
                end <= start
            ) {
                errors[`cuts.${String(index)}`] =
                    'cada corte é [início, fim] com 0 ≤ início < fim, em ordem e sem sobreposição.';
                return null;
            }

            cuts.push([start, end]);
            previousEnd = end;
        }

        return cuts;
    }

    private static items(
        value: unknown,
        field: string,
        max: number,
        errors: Record<string, string>,
    ): Record<string, unknown>[] {
        if (value === undefined || value === null) {
            return [];
        }

        if (!Array.isArray(value) || value.length > max) {
            errors[field] = `${field}: lista de no máximo ${String(max)} itens.`;
            return [];
        }

        return value.map((item: unknown) =>
            typeof item === 'object' && item !== null ? (item as Record<string, unknown>) : {},
        );
    }

    private static unit(value: unknown): boolean {
        return typeof value === 'number' && value >= 0 && value <= 1;
    }
}
