/**
 * Único workflow do microserviço: baixa um vídeo do S3 pela chave, recodifica
 * se o bitrate estiver baixo demais e sobe o `_HQ` de volta ao S3. Quando o
 * reencode não é necessário, não sobe nada — devolve a própria chave de origem.
 *
 * Sem banco: o ciclo de vida fica do lado do Laravel, via callback de webhook.
 */

import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { logger } from '@/config/logger/Logger';
import { S3StorageProvider } from '@/services/storage/S3StorageProvider';
import { reencodeIfNeeded } from '@/services/video/VideoReencoder';
import type { ReencodeResult } from '@/types/DomainType';
import type { IStorageProvider } from '@/types/StorageProviderType';

export class ReencodeWorkflow {
    private readonly storage: IStorageProvider;

    public constructor(storage: IStorageProvider = new S3StorageProvider()) {
        this.storage = storage;
    }

    public async run(
        videoId: string,
        sourceKey: string,
        outputKey: string,
    ): Promise<ReencodeResult> {
        logger.info('='.repeat(50));
        logger.info(`Vídeo: ${videoId} (${sourceKey})`);

        const workDir = await mkdtemp(join(tmpdir(), 'reencode-'));
        try {
            const videoPath = await this.storage.downloadFile(sourceKey, workDir);

            // Recodifica em qualidade constante se o bitrate estiver baixo demais.
            // Devolve o `_HQ.mp4` no mesmo workDir, ou o próprio original.
            const finalPath = await reencodeIfNeeded(videoPath, videoId);

            if (finalPath === videoPath) {
                logger.info('Reencode não necessário; chave de origem mantida.');
                return { videoId, sourceKey, outputKey: sourceKey, reencoded: false };
            }

            await this.storage.uploadFile(finalPath, outputKey);
            logger.info(`Reencode concluído; enviado para '${outputKey}'.`);
            return { videoId, sourceKey, outputKey, reencoded: true };
        } finally {
            await rm(workDir, { recursive: true, force: true });
        }
    }
}
