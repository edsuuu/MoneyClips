/**
 * Transporte HTTP do microserviço (porta API_PORT), sobre Express. Só traduz
 * HTTP para a camada de aplicação (src/app) — sem regra de negócio aqui.
 *
 *   pnpm start   (ou: pnpm run api)
 *
 * Rotas:
 *   GET  /health    — heartbeat (REENCODE_ENABLED, limiar)
 *   POST /reencode  — multipart {video, video_id?}; responde SÍNCRONO:
 *                     200 binário do vídeo recodificado (X-Reencode: completed)
 *                     200 JSON {status: "skipped"} quando não precisou
 */

import express, { type NextFunction, type Request, type Response } from 'express';
import multer from 'multer';
import { randomUUID } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { extname, join } from 'node:path';

import { App } from '@/app';
import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import { sendDiscordError } from '@/services/notifications/Discord';
import { initObservability } from '@/services/observability/RemoteObservability';

const UPLOAD_DIR = join(tmpdir(), 'reencode-uploads');
const MAX_UPLOAD_BYTES = 1024 ** 3; // 1 GB

mkdirSync(UPLOAD_DIR, { recursive: true });

const videoUpload = multer({
    storage: multer.diskStorage({
        destination: (_req, _file, cb) => cb(null, UPLOAD_DIR),
        filename: (_req, file, cb) =>
            cb(null, `${randomUUID()}${extname(file.originalname) || '.mp4'}`),
    }),
    limits: { fileSize: MAX_UPLOAD_BYTES },
}).single('video');

const app = new App();

/**
 * Autoriza a requisição quando API_TOKEN está configurado. Vazio = aberto (dev).
 * Aceita `Authorization: Bearer <token>` ou `X-Api-Token: <token>`.
 */
function requireToken(req: Request, res: Response, next: NextFunction): void {
    if (!settings.apiToken) {
        next();
        return;
    }
    const auth = req.headers['authorization'];
    const bearer = typeof auth === 'string' && auth.startsWith('Bearer ') ? auth.slice(7) : '';
    const headerToken = bearer || req.headers['x-api-token'];
    if (headerToken === settings.apiToken) {
        next();
        return;
    }
    res.status(401).json({ detail: 'não autorizado' });
}

async function removeQuietly(path: string | undefined): Promise<void> {
    if (!path) {
        return;
    }
    try {
        await unlink(path);
    } catch {
        // arquivo já removido/ausente — nada a fazer
    }
}

function main(): void {
    initObservability('reencode');

    const server = express();

    // /health fica aberto (healthcheck), antes do token.
    server.get('/health', (_req, res) => {
        res.json(app.health());
    });

    server.use(requireToken);

    server.post('/reencode', videoUpload, (req, res, next) => {
        void (async (): Promise<void> => {
            const uploaded = req.file?.path;
            if (!uploaded) {
                res.status(422).json({ detail: 'campo "video" (arquivo) é obrigatório' });
                return;
            }

            const videoId = typeof req.body?.video_id === 'string' ? req.body.video_id : '';

            try {
                const result = await app.process(uploaded, videoId);

                if (!result.reencoded) {
                    res.json({ status: 'skipped', reencoded: false });
                    return;
                }

                res.setHeader('X-Reencode', 'completed');
                res.sendFile(result.outputPath, (error) => {
                    if (error) {
                        logger.error(`Falha ao enviar o vídeo recodificado: ${error.message}`);
                    }
                    void removeQuietly(result.outputPath);
                });
            } finally {
                await removeQuietly(uploaded);
            }
        })().catch(next);
    });

    server.use((_req, res) => {
        res.status(404).json({ detail: 'rota não encontrada' });
    });

    // Error-middleware central: upload grande -> 413, o resto -> 500 (com
    // aviso no Discord). O 4º parâmetro (next) é obrigatório para o Express
    // reconhecer isto como handler de erro.
    server.use((error: unknown, req: Request, res: Response, _next: NextFunction): void => {
        if (error instanceof multer.MulterError) {
            const status = error.code === 'LIMIT_FILE_SIZE' ? 413 : 400;
            res.status(status).json({ detail: error.message });
            return;
        }
        const message = error instanceof Error ? error.message : String(error);
        logger.error(`Erro não tratado na rota ${req.method} ${req.url}: ${message}`);
        void sendDiscordError(`rota ${req.method} ${req.url}`, error);
        if (!res.headersSent) {
            res.status(500).json({ detail: 'erro interno' });
        }
    });

    const httpServer = server.listen(settings.apiPort, () => {
        logger.info(`API do reencode ouvindo em http://0.0.0.0:${settings.apiPort}`);
        logger.info(
            `REENCODE_ENABLED=${settings.reencodeEnabled} | limiar=${settings.reencodeBitrateThresholdKbps} kbps`,
        );
    });

    const shutdown = (): void => {
        logger.info('Encerrando API...');
        httpServer.close(() => process.exit(0));
    };
    process.on('SIGINT', shutdown);
    process.on('SIGTERM', shutdown);
}

main();
