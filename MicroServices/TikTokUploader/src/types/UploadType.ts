import type { Browser, BrowserContext } from 'playwright';

import type { VideoMetadata } from './DomainType';

/** Dados necessários para postar um vídeo. */
export interface UploadRequest {
    videoPath: string;
    metadata: VideoMetadata;
    accountName: string;
}

/** Navegador + contexto criados para uma sessão de upload. */
export interface BrowserSession {
    browser: Browser;
    context: BrowserContext;
}
