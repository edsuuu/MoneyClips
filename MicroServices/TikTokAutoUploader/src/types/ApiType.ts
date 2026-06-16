/** Tipos da API HTTP (modo microserviço orquestrado pelo Laravel). */

import type { VideoMetadata } from './DomainType';

/** Job na fila em memória. O navegador é único, então a concorrência é 1. */
export interface QueuedPostJob {
    jobId: string;
    videoId: string;
    /**
     * Chave exata do objeto no storage (ex.: `shorts/{id}/short_{id}.mp4`).
     * Quando ausente, o workflow cai no layout plano `${S3_PREFIX}{id}.mp4`.
     */
    videoKey: string | null;
    webhookUrl: string;
    metadata: VideoMetadata;
}

/**
 * Corpo enviado de volta ao `webhook_url` do Laravel quando o job termina.
 *   completed — publicado no TikTok
 *   dry-run   — processado sem publicar (DRY_RUN ativo)
 *   failed    — erro em qualquer etapa (download, login, publicação)
 */
export interface PostCallback {
    job_id: string;
    video_id: string;
    status: 'completed' | 'dry-run' | 'failed';
    /** false quando os cookies estavam inválidos/ausentes e o login não resolveu. */
    session_valid: boolean;
    /** true quando o motivo da falha foi o login automático não concluir. */
    login_failed: boolean;
    title: string | null;
    error: string | null;
    finished_at: string;
}

/** Resposta do GET /session — checagem leve (sem abrir o navegador). */
export interface SessionView {
    account: string;
    has_cookies: boolean;
    expired: boolean;
    valid: boolean;
}
