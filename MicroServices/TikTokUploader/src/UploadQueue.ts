/**
 * Fila em memória com concorrência 1 — o navegador (e a sessão do TikTok) é um
 * recurso único, então os posts saem em série na ordem de chegada.
 *
 * Ao terminar cada job, o resultado volta para o Laravel no `webhook_url` dele.
 * Qualquer erro é reportado ao Discord (a não ser que já tenha sido, com vídeo,
 * lá na sessão gravada) e também vira `failed` no callback.
 */

import { LoginFailedError } from '@/auth/TikTokAuth';
import { logger } from '@/config/logger/Logger';
import {
    sendDiscordError,
    sendDiscordMessage,
    wasDiscordReported,
} from '@/services/notifications/Discord';
import type { PostCallback, QueuedPostJob } from '@/types/ApiType';
import { sleep } from '@/utils/Sleep';

import type { UploadWorkflow } from './UploadWorkflow';

const CALLBACK_ATTEMPTS = 3;
const CALLBACK_BACKOFF_MS = [1_000, 3_000];

export class UploadQueue {
    private readonly workflow: UploadWorkflow;
    private readonly jobs: QueuedPostJob[] = [];
    private draining = false;

    public constructor(workflow: UploadWorkflow) {
        this.workflow = workflow;
    }

    public enqueue(job: QueuedPostJob): void {
        this.jobs.push(job);
        logger.info(
            `Fila: job ${job.jobId} (vídeo ${job.videoId}) enfileirado. Tamanho: ${this.jobs.length}`,
        );
        void this.drain();
    }

    public get size(): number {
        return this.jobs.length;
    }

    private async drain(): Promise<void> {
        if (this.draining) {
            return;
        }
        this.draining = true;
        try {
            for (let job = this.jobs.shift(); job; job = this.jobs.shift()) {
                await this.process(job);
            }
        } finally {
            this.draining = false;
        }
    }

    private async process(job: QueuedPostJob): Promise<void> {
        try {
            const result = await this.workflow.upload(job.videoId, job.metadata, job.videoKey);

            // 'error' = upload não confirmado pelo TikTok. A sessão era válida
            // (passou do login), mas o post pode não ter saído — alerta no Discord.
            if (result.status === 'error') {
                const detail = 'Upload não confirmado pelo TikTok.';
                await sendDiscordError(
                    `job ${job.jobId} (vídeo ${job.videoId})`,
                    new Error(detail),
                );
                await this.sendCallback(job, {
                    status: 'failed',
                    session_valid: true,
                    login_failed: false,
                    title: result.title,
                    error: detail,
                });
                return;
            }

            await this.sendCallback(job, {
                status: result.status,
                session_valid: true,
                login_failed: false,
                title: result.title,
                error: null,
            });
            // Notificação de sucesso fica do lado do Laravel (callback recebe e
            // dispara DiscordNotifier::success). Aqui só erros, pra não duplicar.
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            const loginFailed = error instanceof LoginFailedError;
            logger.error(`Fila: job ${job.jobId} falhou: ${message}`);

            // Erros na sessão do navegador já foram ao Discord com o vídeo; aqui
            // cobrimos os demais (ex.: download do S3) para nada passar batido.
            if (!wasDiscordReported(error)) {
                await sendDiscordError(`job ${job.jobId} (vídeo ${job.videoId})`, error);
            }

            await this.sendCallback(job, {
                status: 'failed',
                session_valid: !loginFailed,
                login_failed: loginFailed,
                title: job.metadata.title,
                error: message,
            });
        }
    }

    /**
     * POSTa o resultado de volta no webhook_url do Laravel, com algumas
     * tentativas (sem banco, o callback é o ÚNICO retorno — não pode se perder
     * por um soluço de rede). Esgotadas as tentativas, alerta no Discord para
     * intervenção manual.
     */
    private async sendCallback(
        job: QueuedPostJob,
        partial: Omit<PostCallback, 'job_id' | 'video_id' | 'finished_at'>,
    ): Promise<void> {
        const payload: PostCallback = {
            job_id: job.jobId,
            video_id: job.videoId,
            finished_at: new Date().toISOString(),
            ...partial,
        };

        for (let attempt = 1; attempt <= CALLBACK_ATTEMPTS; attempt += 1) {
            try {
                const resp = await fetch(job.webhookUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (resp.ok) {
                    return;
                }
                logger.warn(
                    `Webhook respondeu ${resp.status} (job ${job.jobId}, tentativa ${attempt}/${CALLBACK_ATTEMPTS}).`,
                );
            } catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                logger.warn(
                    `Falha no webhook (job ${job.jobId}, tentativa ${attempt}/${CALLBACK_ATTEMPTS}): ${message}`,
                );
            }
            if (attempt < CALLBACK_ATTEMPTS) {
                await sleep(CALLBACK_BACKOFF_MS[attempt - 1] ?? 3_000);
            }
        }

        await sendDiscordMessage(
            `⚠️ Webhook do Laravel não confirmou após ${CALLBACK_ATTEMPTS} tentativas (job ${job.jobId}, ` +
                `vídeo ${job.videoId}). Resultado: ${payload.status}. Reconcilie manualmente.`,
        );
    }
}
