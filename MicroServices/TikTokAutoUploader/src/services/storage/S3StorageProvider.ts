/**
 * Acesso ao Contabo Object Storage via SDK S3 oficial da AWS (v3).
 *
 * O Contabo exige assinatura SigV4 + path-style + region us-east-1; é por isso
 * que `forcePathStyle: true` está fixo aqui (o equivalente ao addressing_style
 * 'path' que tivemos que usar no boto3).
 */

import { GetObjectCommand, S3Client } from '@aws-sdk/client-s3';
import { createWriteStream } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import { basename, join } from 'node:path';
import { Readable, Transform, type TransformCallback } from 'node:stream';
import { pipeline } from 'node:stream/promises';

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import type { IStorageProvider } from '@/types/StorageProviderType';
import { sleep } from '@/utils/Sleep';

export class S3StorageProvider implements IStorageProvider {
    private readonly client: S3Client;
    private readonly bucket: string;

    public constructor() {
        this.bucket = settings.awsBucket;
        this.client = new S3Client({
            endpoint: settings.awsEndpoint,
            region: settings.awsRegion,
            forcePathStyle: true,
            credentials: {
                accessKeyId: settings.awsAccessKeyId,
                secretAccessKey: settings.awsSecretAccessKey,
            },
        });
    }

    public async downloadFile(key: string, destDir: string): Promise<string> {
        await mkdir(destDir, { recursive: true });
        const filename = basename(key);
        if (!filename) {
            throw new Error(`Chave de objeto inválida: '${key}'.`);
        }

        const destPath = join(destDir, filename);
        logger.info(`  Baixando: ${key}`);

        // Retry com backoff exponencial: erros transitórios (ex.: MinIO fora do
        // ar, ECONNREFUSED, timeout, 5xx) não devem falhar o job de primeira.
        // Chave inexistente (404) não adianta retentar — falha na hora.
        const maxAttempts = Math.max(1, settings.s3DownloadRetries);
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                // Tamanho 0 = o progresso usa o ContentLength da resposta.
                // createWriteStream trunca o arquivo, então retry sobrescreve limpo.
                await this.downloadObject(key, destPath, 0);
                return destPath;
            } catch (error) {
                // O Contabo responde erros pouco descritivos (ex.: "UnknownError")
                // para chave inexistente — anexa a chave para o ledger ficar útil.
                const message = error instanceof Error ? error.message : String(error);

                if (isNotFound(error) || attempt >= maxAttempts) {
                    const tries = isNotFound(error) ? '' : ` após ${maxAttempts} tentativa(s)`;
                    throw new Error(`Falha ao baixar '${key}' do S3${tries}: ${message}`);
                }

                const delay = settings.s3DownloadRetryDelayMs * 2 ** (attempt - 1);
                logger.warn(
                    `  Download de '${key}' falhou (tentativa ${attempt}/${maxAttempts}): ` +
                        `${message}. Retentando em ${Math.round(delay / 1000)}s...`,
                );
                await sleep(delay);
            }
        }

        // Inalcançável (o loop sempre retorna ou lança), mas satisfaz o tipo.
        throw new Error(`Falha ao baixar '${key}' do S3.`);
    }

    private async downloadObject(key: string, destPath: string, totalBytes: number): Promise<void> {
        const resp = await this.client.send(
            new GetObjectCommand({ Bucket: this.bucket, Key: key }),
        );
        const body = resp.Body;
        if (!(body instanceof Readable)) {
            throw new Error(`Corpo inesperado ao baixar '${key}' do S3.`);
        }
        const total = totalBytes || Number(resp.ContentLength ?? 0);
        await pipeline(body, progressCounter(total), createWriteStream(destPath));
    }
}

/**
 * Erro de "objeto não existe" (404 / NoSuchKey / NotFound) — não-retentável.
 * Lê o nome do erro e o httpStatusCode dos metadados do SDK v3 sem assumir tipo.
 */
function isNotFound(error: unknown): boolean {
    if (typeof error !== 'object' || error === null) {
        return false;
    }
    const name = 'name' in error ? String((error as { name: unknown }).name) : '';
    if (name === 'NoSuchKey' || name === 'NotFound') {
        return true;
    }
    const metadata = (error as { $metadata?: { httpStatusCode?: number } }).$metadata;
    return metadata?.httpStatusCode === 404;
}

/**
 * Transform que reporta a % de download. Só mostra progresso para arquivos
 * grandes (> 1 MB) — arquivos pequenos baixam instantaneamente. Em terminal
 * (TTY) usa uma barra que se atualiza na mesma linha; redirecionado para arquivo,
 * loga marcos de 10%.
 */
function progressCounter(total: number): Transform {
    const showProgress = total > 1_000_000;
    const isTty = process.stdout.isTTY === true;
    let received = 0;
    let nextMark = 10;

    return new Transform({
        transform(chunk: Buffer, _encoding: BufferEncoding, callback: TransformCallback): void {
            received += chunk.length;
            if (showProgress) {
                const pct = Math.min(100, Math.floor((received / total) * 100));
                if (isTty) {
                    process.stdout.write(`\r    download: ${pct}%   `);
                    if (pct >= 100) {
                        process.stdout.write('\n');
                    }
                } else {
                    while (pct >= nextMark && nextMark <= 100) {
                        logger.info(`    download: ${nextMark}%`);
                        nextMark += 10;
                    }
                }
            }
            callback(null, chunk);
        },
    });
}
