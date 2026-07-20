import { GetObjectCommand, PutObjectCommand, S3Client } from '@aws-sdk/client-s3';
import { createWriteStream } from 'node:fs';
import { readdir, readFile } from 'node:fs/promises';
import { join, relative, sep } from 'node:path';
import type { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';

/**
 * Acesso ao storage S3-compatível (endpoint/credencial vêm do ambiente — AWS
 * S3, Contabo, MinIO em dev...). Exceção consciente à regra "só o Laravel toca
 * o S3": um vídeo longo vira milhares de segmentos, e trafegá-los por HTTP até
 * o Laravel para subir um a um seria inviável. A policy da credencial usada
 * aqui deve ser restrita a leitura em `uploads/*` e escrita em `hls/*`.
 */
export class S3Storage extends Logger {
    private static readonly CONTENT_TYPES: Record<string, string> = {
        '.m3u8': 'application/vnd.apple.mpegurl',
        '.m4s': 'video/iso.segment',
        '.mp4': 'video/mp4',
        '.jpg': 'image/jpeg',
    };

    private static readonly UPLOAD_CONCURRENCY = 8;

    private readonly client: S3Client;

    public constructor() {
        super();
        this.client = new S3Client({
            endpoint: settings.storageEndpoint,
            region: settings.storageRegion,
            forcePathStyle: settings.storageForcePathStyle,
            credentials: {
                accessKeyId: settings.storageAccessKey,
                secretAccessKey: settings.storageSecretKey,
            },
        });
    }

    public async download(key: string, destination: string): Promise<void> {
        const result = await this.client.send(
            new GetObjectCommand({ Bucket: settings.storageBucket, Key: key }),
        );

        if (!result.Body) {
            throw new Error(`Objeto vazio no storage: ${key}`);
        }

        await pipeline(result.Body as Readable, createWriteStream(destination));
    }

    public async uploadDirectory(localDir: string, keyPrefix: string): Promise<number> {
        const files = await this.walk(localDir);
        let cursor = 0;

        const worker = async (): Promise<void> => {
            while (cursor < files.length) {
                const file = files[cursor];
                cursor += 1;
                if (file === undefined) {
                    continue;
                }

                const relativePath = relative(localDir, file).split(sep).join('/');
                const extension = relativePath.slice(relativePath.lastIndexOf('.'));

                await this.client.send(
                    new PutObjectCommand({
                        Bucket: settings.storageBucket,
                        Key: `${keyPrefix}/${relativePath}`,
                        Body: await readFile(file),
                        ContentType:
                            S3Storage.CONTENT_TYPES[extension] ?? 'application/octet-stream',
                    }),
                );
            }
        };

        const workers = Array.from(
            { length: Math.min(S3Storage.UPLOAD_CONCURRENCY, files.length) },
            () => worker(),
        );

        await Promise.all(workers);
        this.info(`Subiu ${String(files.length)} arquivos para ${keyPrefix}/`);

        return files.length;
    }

    private async walk(dir: string): Promise<string[]> {
        const entries = await readdir(dir, { withFileTypes: true });
        const files: string[] = [];

        for (const entry of entries) {
            const full = join(dir, entry.name);
            if (entry.isDirectory()) {
                files.push(...(await this.walk(full)));
                continue;
            }
            files.push(full);
        }

        return files;
    }
}
