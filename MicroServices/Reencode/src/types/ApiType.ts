/** Contratos da API do reencode: job enfileirado e payload do callback. */

/** Status final de um job de reencode. */
export type ReencodeStatus = 'completed' | 'skipped' | 'failed';

/** Job de reencode aceito pela API e enfileirado. */
export interface QueuedReencodeJob {
    jobId: string;
    videoId: string;
    /** Chave exata do objeto de origem no storage. */
    sourceKey: string;
    /** Chave de destino do arquivo recodificado (derivada se não informada). */
    outputKey: string;
    webhookUrl: string;
}

/** Corpo POSTado de volta no webhook_url do Laravel ao fim de cada job. */
export interface ReencodeCallback {
    job_id: string;
    video_id: string;
    /**
     * completed — recodificado e enviado para `output_key`.
     * skipped   — reencode desnecessário/desabilitado; `output_key` == `source_key`.
     * failed    — erro; `error` descreve.
     */
    status: ReencodeStatus;
    source_key: string;
    /** Chave a usar a jusante: o `_HQ` quando recodificado, senão a de origem. */
    output_key: string;
    reencoded: boolean;
    error: string | null;
    finished_at: string;
}
