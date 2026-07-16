import { randomUUID } from 'node:crypto';
import { rm } from 'node:fs/promises';

import { logger } from '@/Config/Logger';
import { LoginFailedError } from '@/Exceptions/LoginFailedError';
import { TikTokContentRestrictionError } from '@/Exceptions/TikTokContentRestrictionError';
import { discord } from '@/Services/Notifications/Discord';
import { TikTokUploader } from '@/Services/TikTok/TikTokUploader';
import { sendWebhook } from '@/Services/WebhookService';
import type { PostJob, PostWebhookPayload } from '@/Types/PostQueueType';

/**
 * Fila em memória do POST /posts: o request responde 202 {job_id} na hora e
 * o upload (Playwright, até ~15 min de verificação de conteúdo) roda aqui em
 * background, um por vez — ao terminar, o desfecho vai pro Laravel via
 * webhook (status + session_status + cookies renovados).
 *
 * ponytail: fila serial por promise-chain, sem persistência — reiniciar o
 * processo perde jobs pendentes (o Laravel detecta pelo check-missed e o
 * ledger fica em `queued`). Upgrade: persistir a fila em disco.
 */
export class PostQueueService {
    private chain: Promise<void> = Promise.resolve();
    private pending = 0;

    public constructor(private readonly uploader: TikTokUploader = new TikTokUploader()) {}

    /** Enfileira e devolve o job_id imediatamente. */
    public enqueue(job: Omit<PostJob, 'jobId'>): string {
        const jobId = randomUUID();

        this.pending += 1;
        this.chain = this.chain
            .then(() => this.process({ ...job, jobId }))
            .finally(() => {
                this.pending -= 1;
            });

        logger.info(`[job ${jobId}] Post enfileirado (${this.pending} na fila).`);

        return jobId;
    }

    public size(): number {
        return this.pending;
    }

    /** Nunca lança: qualquer desfecho vira webhook + limpeza do arquivo. */
    private async process(job: PostJob): Promise<void> {
        const title = job.metadata.title || '(sem título)';
        let payload: PostWebhookPayload;

        logger.info(`[job ${job.jobId}] Iniciando upload de "${title}"...`);

        try {
            const result = await this.uploader.upload({
                videoPath: job.videoPath,
                metadata: job.metadata,
                cookies: job.cookies,
            });

            payload = {
                job_id: job.jobId,
                status: result.status,
                title,
                session_status: 'valid',
                ...(result.refreshedCookies.length > 0
                    ? { refreshed_cookies: result.refreshedCookies }
                    : {}),
            };

            void discord.notifySuccess(
                '✅ Post no TikTok',
                `\`${title}\` — status: ${result.status}`,
            );
        } catch (error) {
            payload = this.failurePayload(job.jobId, title, error);
        } finally {
            await rm(job.videoPath, { force: true }).catch(() => undefined);
        }

        logger.info(`[job ${job.jobId}] Desfecho: ${payload.status} — notificando webhook.`);
        await sendWebhook(job.webhookUrl, payload);
    }

    private failurePayload(jobId: string, title: string, error: unknown): PostWebhookPayload {
        if (error instanceof TikTokContentRestrictionError) {
            logger.warn(`[job ${jobId}] TikTok restringiu o post: ${error.message}`);

            return {
                job_id: jobId,
                status: 'restricted',
                title,
                detail: error.message,
                session_status: 'valid',
            };
        }

        if (error instanceof LoginFailedError) {
            logger.warn(`[job ${jobId}] Sessão inválida: ${error.message}`);

            return {
                job_id: jobId,
                status: 'failed',
                title,
                error: error.message,
                session_status: 'invalid',
            };
        }

        const message = error instanceof Error ? error.message : String(error);
        logger.error(`[job ${jobId}] Upload falhou: ${message}`);
        void discord.notifyError(`post "${title}" (job ${jobId})`, error);

        return {
            job_id: jobId,
            status: 'failed',
            title,
            error: message,
            session_status: 'unknown',
        };
    }
}
