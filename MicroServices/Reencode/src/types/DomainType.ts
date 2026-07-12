/** Tipos de domínio do reencode. Dados puros — sem comportamento. */

/** Resultado do workflow de reencode, para o callback do webhook. */
export interface ReencodeResult {
    videoId: string;
    sourceKey: string;
    /** Chave a usar a jusante: o `_HQ` quando recodificado, senão a de origem. */
    outputKey: string;
    reencoded: boolean;
}
