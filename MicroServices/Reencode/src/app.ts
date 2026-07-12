/**
 * Camada de aplicação: as regras do serviço, independentes de HTTP. Recebe a
 * entrada já desserializada, valida, resolve as chaves de origem/destino e
 * enfileira. O transporte (src/server) só traduz HTTP <-> estes métodos.
 */

import { randomUUID } from 'node:crypto';
import { z } from 'zod';

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';

import { ReencodeQueue } from './ReencodeQueue';
import { ReencodeWorkflow } from './ReencodeWorkflow';

const ReencodeSchema = z.object({
    video_id: z.string().min(1),
    // Chave exata do objeto de origem. Sem ela, usa o layout aninhado do
    // download-shorts: `${S3_PREFIX}{id}/short_{id}.mp4`.
    source_key: z.string().min(1).optional(),
    // Chave de destino do `_HQ`. Sem ela, deriva da origem (sufixo `_HQ`).
    output_key: z.string().min(1).optional(),
    webhook_url: z.string().url(),
});

/** Payload da API rejeitado pela validação — o servidor traduz para HTTP 422. */
export class ValidationError extends Error {
    public constructor(public readonly details: unknown) {
        super('Payload inválido.');
        this.name = 'ValidationError';
    }
}

export interface Health {
    status: 'ok';
    queue_size: number;
    reencode_enabled: boolean;
}

export class App {
    private readonly queue: ReencodeQueue;

    public constructor(queue: ReencodeQueue = new ReencodeQueue(new ReencodeWorkflow())) {
        this.queue = queue;
    }

    public health(): Health {
        return {
            status: 'ok',
            queue_size: this.queue.size,
            reencode_enabled: settings.reencodeEnabled,
        };
    }

    /** Valida o payload, enfileira o job e devolve o id. */
    public enqueue(input: unknown): { job_id: string; status: 'queued' } {
        const parsed = ReencodeSchema.safeParse(input);
        if (!parsed.success) {
            throw new ValidationError(parsed.error.flatten());
        }

        const videoId = parsed.data.video_id;
        const sourceKey =
            parsed.data.source_key ?? `${settings.s3Prefix}${videoId}/short_${videoId}.mp4`;
        const outputKey = parsed.data.output_key ?? deriveHqKey(sourceKey);

        const jobId = randomUUID();
        logger.info(
            `POST /reencode — job ${jobId} videoId=${videoId} source=${sourceKey} output=${outputKey}`,
        );
        this.queue.enqueue({
            jobId,
            videoId,
            sourceKey,
            outputKey,
            webhookUrl: parsed.data.webhook_url,
        });
        return { job_id: jobId, status: 'queued' };
    }
}

/** Insere `_HQ` antes da extensão da chave (`a/b.mp4` -> `a/b_HQ.mp4`). */
function deriveHqKey(key: string): string {
    const dot = key.lastIndexOf('.');
    const slash = key.lastIndexOf('/');
    if (dot <= slash) {
        // Sem extensão após a última barra: só anexa.
        return `${key}_HQ.mp4`;
    }
    return `${key.slice(0, dot)}_HQ${key.slice(dot)}`;
}
