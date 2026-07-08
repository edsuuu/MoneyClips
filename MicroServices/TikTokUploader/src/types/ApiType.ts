/** Tipos da API HTTP (modo microserviço orquestrado pelo Laravel). */

import type { Cookie, VideoMetadata } from './DomainType';

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
    /**
     * Cookies enviados pelo Laravel a cada POST. Quando presentes, são gravados
     * no disco do container antes do upload — substituem o cookies/{name}.json
     * antigo. Null = usa o que está em disco (fallback de dev).
     */
    cookies: Cookie[] | null;
}

/**
 * Corpo enviado de volta ao `webhook_url` do Laravel quando o job termina.
 *   completed — publicado no TikTok
 *   dry-run   — processado sem publicar (DRY_RUN ativo)
 *   restricted — TikTok marcou o conteúdo como restrito antes/depois do clique
 *   failed    — erro em qualquer etapa (download, login, publicação)
 */
export interface PostCallback {
    job_id: string;
    video_id: string;
    status: 'completed' | 'dry-run' | 'restricted' | 'failed';
    /** false quando os cookies estavam inválidos/ausentes e o login não resolveu. */
    session_valid: boolean;
    /** true quando o motivo da falha foi o login automático não concluir. */
    login_failed: boolean;
    title: string | null;
    error: string | null;
    finished_at: string;
    /**
     * Cookies lidos APÓS o post — captura refresh que o TikTok faz na sessão.
     * Laravel salva no social_accounts pra manter a fonte da verdade fresca.
     */
    refreshed_cookies?: Cookie[] | null;
    /**
     * Estado da sessão visto pelo uploader: valid (post OK ou cookies bons),
     * invalid (cookies expiraram e re-login falhou), unknown (não pôde checar).
     */
    session_status?: 'valid' | 'invalid' | 'unknown';
}

/** Resposta do GET /session — checagem leve (sem abrir o navegador). */
export interface SessionView {
    account: string;
    has_cookies: boolean;
    expired: boolean;
    valid: boolean;
}
