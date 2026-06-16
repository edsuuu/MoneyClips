/**
 * Único workflow do microserviço: baixa um vídeo do S3 pelo id e publica no
 * TikTok com o título/hashtags recebidos no payload da API.
 *
 * O id vira a chave `${S3_PREFIX}${id}.mp4` (arquivos planos que o microserviço
 * download-shorts sobe). Sem JSON sidecar, sem layout de pastas, sem tracker —
 * o ciclo de vida de cada post fica do lado do Laravel, via callback de webhook.
 */

import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import { S3StorageProvider } from '@/services/storage/S3StorageProvider';
import { TikTokUploader } from '@/services/tiktok/TikTokUploader';
import type { VideoMetadata, WorkflowResult } from '@/types/DomainType';
import type { IStorageProvider } from '@/types/StorageProviderType';

export class UploadWorkflow {
    private readonly storage: IStorageProvider;
    private readonly uploader: TikTokUploader;

    public constructor(
        storage: IStorageProvider = new S3StorageProvider(),
        uploader: TikTokUploader = new TikTokUploader(),
    ) {
        this.storage = storage;
        this.uploader = uploader;
    }

    public async upload(
        videoId: string,
        metadata: VideoMetadata,
        videoKey: string | null = null,
    ): Promise<WorkflowResult> {
        // Chave exata do storage (layout aninhado do download-shorts) quando o
        // Laravel a envia; senão, layout plano legado `${S3_PREFIX}{id}.mp4`.
        const objectKey = videoKey ?? `${settings.s3Prefix}${videoId}.mp4`;
        logger.info('='.repeat(50));
        logger.info(`Vídeo: ${videoId} (${objectKey})`);
        logger.info(`  Título   : ${metadata.title}`);
        logger.info(`  Hashtags : ${metadata.hashtags.join(' ') || '(nenhuma)'}`);
        logger.info(`  Conta    : ${settings.tiktokAccountName}`);

        const workDir = await mkdtemp(join(tmpdir(), 'tiktok-'));
        try {
            const videoPath = await this.storage.downloadFile(objectKey, workDir);

            const result = await this.uploader.upload({
                videoPath,
                metadata,
                accountName: settings.tiktokAccountName,
            });

            logger.info(`  Resultado: ${result}`);
            return { status: result, videoId, title: metadata.title };
        } finally {
            await rm(workDir, { recursive: true, force: true });
        }
    }
}
