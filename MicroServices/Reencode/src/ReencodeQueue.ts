/**
 * Fila em memória com concorrência 1 — o reencode (ffmpeg/NVENC) é pesado de
 * CPU/GPU, então os jobs saem em série na ordem de chegada.
 *
 * Ao terminar cada job, o resultado volta para o Laravel no `webhook_url` dele.
 * Qualquer erro é reportado ao Discord e vira `failed` no callback.
 */

import { logger } from '@/config/logger/Logger';
import { sendDiscordError, sendDiscordMessage } from '@/services/notifications/Discord';
import type { QueuedReencodeJob, ReencodeCallback } from '@/types/ApiType';
import { sleep } from '@/utils/Sleep';

import type { ReencodeWorkflow } from './ReencodeWorkflow';

const CALLBACK_ATTEMPTS = 3;
const CALLBACK_BACKOFF_MS = [1_000, 3_000];

export class ReencodeQueue {
    private readonly workflow: ReencodeWorkflow;
    private readonly jobs: QueuedReencodeJob[] = [];
    private draining = false;

    public constructor(workflow: ReencodeWorkflow) {
        this.workflow = workflow;
    }

    public enqueue(job: QueuedReencodeJob): void {
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

    private async process(job: QueuedReencodeJob): Promise<void> {
        try {
            const result = await this.workflow.run(job.videoId, job.sourceKey, job.outputKey);
            logger.info(
                `Fila: job ${job.jobId} resultado=${result.reencoded ? 'completed' : 'skipped'} ` +
                    `output=${result.outputKey}`,
            );
            await this.sendCallback(job, {
                status: result.reencoded ? 'completed' : 'skipped',
                output_key: result.outputKey,
                reencoded: result.reencoded,
                error: null,
            });
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            logger.error(`Fila: job ${job.jobId} falhou: ${message}`);
            await sendDiscordError(`job ${job.jobId} (vídeo ${job.videoId})`, error);
            await this.sendCallback(job, {
                status: 'failed',
                // Falhou: a jusante deve seguir com a chave de origem intacta.
                output_key: job.sourceKey,
                reencoded: false,
                error: message,
            });
        }
    }

    /**
     * POSTa o resultado de volta no webhook_url do Laravel, com algumas
     * tentativas (sem banco, o callback é o ÚNICO retorno — não pode se perder
     * por um soluço de rede). Esgotadas as tentativas, alerta no Discord.
     */
    private async sendCallback(
        job: QueuedReencodeJob,
        partial: Omit<ReencodeCallback, 'job_id' | 'video_id' | 'source_key' | 'finished_at'>,
    ): Promise<void> {
        const payload: ReencodeCallback = {
            job_id: job.jobId,
            video_id: job.videoId,
            source_key: job.sourceKey,
            finished_at: new Date().toISOString(),
            ...partial,
        };

        logger.info(
            `Webhook → ${job.webhookUrl} (job ${job.jobId}, status=${payload.status}, ` +
                `output=${payload.output_key}).`,
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
