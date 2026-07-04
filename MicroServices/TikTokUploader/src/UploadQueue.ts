/**
 * Fila em memória com concorrência 1 — o navegador (e a sessão do TikTok) é um
 * recurso único, então os posts saem em série na ordem de chegada.
 *
 * Ao terminar cada job, o resultado volta para o Laravel no `webhook_url` dele.
 * Qualquer erro é reportado ao Discord (a não ser que já tenha sido, com vídeo,
 * lá na sessão gravada) e também vira `failed` no callback.
 */

import { normalizeCookies, readCookies, saveCookies } from '@/auth/Cookies';
import { LoginFailedError } from '@/auth/TikTokAuth';
import { settings } from '@/config/env/Env';
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
        const account = settings.tiktokAccountName;

        // Cookies vindos do Laravel substituem o arquivo em disco. Gravamos
        // antes do upload pra Playwright pegar a sessão atualizada.
        if (job.cookies && job.cookies.length > 0) {
            logger.info(
                `Fila: job ${job.jobId} injetando ${job.cookies.length} cookies recebidos do Laravel.`,
            );
            await saveCookies(account, normalizeCookies(job.cookies));
        } else {
            logger.warn(
                `Fila: job ${job.jobId} sem cookies no payload — usando fallback do disco.`,
            );
        }

        try {
            const result = await this.workflow.upload(job.videoId, job.metadata, job.videoKey);

            // Captura cookies refrescados pra o Laravel atualizar o banco.
            const refreshedCookies = await this.captureCookies(account, job.jobId);

            logger.info(
                `Fila: job ${job.jobId} resultado=${result.status} title="${result.title ?? ''}"`,
            );
            // @ts-ignore
            await this.sendCallback(job, {
                status: result.status,
                session_valid: true,
                login_failed: false,
                title: result.title,
                error: null,
                refreshed_cookies: refreshedCookies,
                session_status: 'valid',
            });
            // Notificação de sucesso fica do lado do Laravel (callback recebe e
            // dispara DiscordNotifier::success). Aqui só erros, pra não duplicar.
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            const loginFailed = error instanceof LoginFailedError;
            logger.error(
                `Fila: job ${job.jobId} falhou: ${message} (login_failed=${loginFailed})`,
            );

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
                refreshed_cookies: null,
                // Sessão é invalida só quando o login automático bateu na parede;
                // outros erros (download, rede) não significam cookies ruins.
                session_status: loginFailed ? 'invalid' : 'unknown',
            });
        }
    }

    /** Lê os cookies do disco após o upload — captura refresh feito pelo TikTok. */
    private async captureCookies(account: string, jobId: string): Promise<PostCallback['refreshed_cookies']> {
        try {
            const cookies = await readCookies(account);
            logger.info(`Fila: job ${jobId} capturou ${cookies.length} cookies para devolver ao Laravel.`);
            return cookies;
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            logger.warn(`Fila: job ${jobId} não conseguiu ler cookies pós-upload: ${message}`);
            return null;
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

        const refreshedCount = payload.refreshed_cookies?.length ?? 0;
        logger.info(
            `Webhook → ${job.webhookUrl} (job ${job.jobId}, status=${payload.status}, ` +
                `session=${payload.session_status ?? '-'}, refreshed_cookies=${refreshedCount}).`,
        );

        for (let attempt = 1; attempt <= CALLBACK_ATTEMPTS; attempt += 1) {
            try {
                const resp = await fetch(job.webhookUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (resp.ok) {
                    logger.info(
                        `Webhook ACEITO (job ${job.jobId}, HTTP ${resp.status}, tentativa ${attempt}/${CALLBACK_ATTEMPTS}).`,
                    );
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

        logger.error(
            `Webhook REJEITADO após ${CALLBACK_ATTEMPTS} tentativas (job ${job.jobId}). Reconcilie manualmente.`,
        );
        await sendDiscordMessage(
            `⚠️ Webhook do Laravel não confirmou após ${CALLBACK_ATTEMPTS} tentativas (job ${job.jobId}, ` +
                `vídeo ${job.videoId}). Resultado: ${payload.status}. Reconcilie manualmente.`,
        );
    }
}
