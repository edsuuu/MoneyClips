/**
 * Camada de aplicação: recodifica um arquivo local se o bitrate estiver
 * abaixo do limiar. Regra da casa: o serviço NÃO toca no S3 — o Laravel envia
 * o binário por multipart e recebe o resultado na resposta.
 *
 * ffmpeg é pesado: `process` serializa as execuções (1 por vez) com uma
 * promise-chain — substitui a antiga fila em memória + webhook.
 */

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import { reencodeIfNeeded } from '@/services/video/VideoReencoder';

export interface Health {
    status: 'ok';
    reencode_enabled: boolean;
    threshold_kbps: number;
}

export interface ProcessResult {
    reencoded: boolean;
    /** Path local do arquivo final (o `_HQ.mp4` ou o próprio original). */
    outputPath: string;
}

export class App {
    // ponytail: lock global via promise-chain — 1 ffmpeg por vez. Se um dia
    // precisar de paralelismo, o upgrade é uma fila com concorrência N.
    private chain: Promise<unknown> = Promise.resolve();

    public health(): Health {
        return {
            status: 'ok',
            reencode_enabled: settings.reencodeEnabled,
            threshold_kbps: settings.reencodeBitrateThresholdKbps,
        };
    }

    /** Recodifica (serializado). Nunca lança por causa do reencode em si. */
    public async process(videoPath: string, videoId: string): Promise<ProcessResult> {
        const run = async (): Promise<ProcessResult> => {
            logger.info('='.repeat(50));
            logger.info(`Reencode solicitado: videoId=${videoId || 'sem-id'} arquivo=${videoPath}`);
            const outputPath = await reencodeIfNeeded(videoPath, videoId || 'sem-id');
            return { reencoded: outputPath !== videoPath, outputPath };
        };

        const next = this.chain.then(run, run);
        this.chain = next.catch(() => undefined);
        return next;
    }
}
