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
 * processo perde jobs pendentes (ledger fica em `queued`; o
 * auto-post:check-missed do Laravel alerta pendência velha). O shutdown
 * drena a fila (App.ts) pra não matar o Playwright no meio de um post.
 * Upgrade: persistir a fila em disco.
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
            // process() não deveria lançar, mas uma rejeição aqui envenenaria
            // a chain e pularia todos os jobs seguintes em silêncio.
            .catch((error: unknown) =>
                logger.error(
                    `[job ${jobId}] Erro inesperado fora do fluxo do job: ` +
                        `${error instanceof Error ? error.message : String(error)}`,
                ),
            )
            .finally(() => {
                this.pending -= 1;
            });

        logger.info(`[job ${jobId}] Post enfileirado (${this.pending} na fila).`);

        return jobId;
    }

    public size(): number {
        return this.pending;
    }

    /** Resolve quando todos os jobs enfileirados terminarem (shutdown gracioso). */
    public drain(): Promise<void> {
        return this.chain;
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
            await rm(job.videoPath, { force: true }).catch((error: unknown) =>
                logger.warn(
                    `[job ${job.jobId}] Falha ao remover o vídeo temporário: ` +
                        `${error instanceof Error ? error.message : String(error)}`,
                ),
            );
        }

        if (job.accountId !== undefined) {
            payload.account_id = job.accountId;
        }

        logger.info(`[job ${job.jobId}] Desfecho: ${payload.status} — notificando webhook.`);

        // Webhook esgotado = desfecho invisível pro Laravel (ledger preso em
        // queued) — o operador precisa saber na hora.
        if (!(await sendWebhook(job.webhookUrl, payload))) {
            void discord.notifyError(
                `webhook do post "${title}" (job ${job.jobId})`,
                new Error(`Laravel não recebeu o desfecho "${payload.status}" — confira o ledger.`),
            );
        }
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

        // Sem Discord aqui: o runRecordedSession (Browser.ts) já notificou a
        // falha com o vídeo da sessão anexado — segundo alerta seria ruído.
        const message = error instanceof Error ? error.message : String(error);
        logger.error(`[job ${jobId}] Upload falhou: ${message}`);

        return {
            job_id: jobId,
            status: 'failed',
            title,
            error: message,
            session_status: 'unknown',
        };
    }
}

/** Instância única — compartilhada entre Routers (enqueue) e App (drain). */
export const postQueue = new PostQueueService();
