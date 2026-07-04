/** Tipos de domínio do uploader. Dados puros — sem comportamento. */

/** Cookie do Playwright/TikTok, no formato que `context.addCookies` consome. */
export interface Cookie {
    name: string;
    value: string;
    domain: string;
    path: string;
    expires?: number;
    httpOnly?: boolean;
    secure?: boolean;
    sameSite?: 'Strict' | 'Lax' | 'None';
}

/** Metadados de um vídeo, já normalizados a partir do JSON do S3. */
export interface VideoMetadata {
    title: string;
    hashtags: string[];
    soundName: string | null;
    soundVolume: SoundVolume;
}

export type SoundVolume = 'mix' | 'main' | 'background';

/**
 * Resultado de uma tentativa de upload. Falha (inclusive publicação não
 * confirmada) é exceção — assim a sessão gravada anexa o vídeo no Discord.
 *   completed — publicado com sucesso
 *   dry-run   — processou tudo mas não publicou (DRY_RUN ativo)
 */
export type UploadResult = 'completed' | 'dry-run';

/** Retorno detalhado do workflow, para logs e notificações externas. */
export interface WorkflowResult {
    status: UploadResult;
    videoId: string;
    title: string;
}

/** Caixa delimitadora retornada pelo Roboflow (centro x/y + dimensões). */
export interface BoundingBox {
    x: number;
    y: number;
    width: number;
    height: number;
}

/** Coordenada em pixels na viewport do navegador. */
export interface Point {
    x: number;
    y: number;
}
