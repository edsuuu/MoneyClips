/**
 * Transporte HTTP do microserviço (porta API_PORT), sobre Express. Só traduz
 * HTTP para a camada de aplicação (src/app) — sem regra de negócio aqui.
 *
 *   pnpm start   (ou: pnpm run api)
 *
 * Rotas:
 *   GET  /health    — heartbeat (tamanho da fila, REENCODE_ENABLED)
 *   POST /reencode  — enfileira um job; responde 202 e avisa no webhook ao fim
 */

import express, { type NextFunction, type Request, type Response } from 'express';

import { App, ValidationError } from '@/app';
import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import { sendDiscordError } from '@/services/notifications/Discord';

const MAX_BODY_BYTES = 1_000_000;

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

function main(): void {
    const server = express();

    // /health fica aberto (healthcheck do container), antes do token.
    server.get('/health', (_req, res) => {
        res.json(app.health());
    });

    // Corpo JSON com teto de tamanho; o resto das rotas exige token.
    server.use(express.json({ limit: MAX_BODY_BYTES }));
    server.use(requireToken);

    server.post('/reencode', (req, res) => {
        res.status(202).json(app.enqueue(req.body));
    });

    server.use((_req, res) => {
        res.status(404).json({ detail: 'rota não encontrada' });
    });

    // Error-middleware central: ValidationError -> 422, JSON malformado/grande
    // -> 400, o resto -> 500 (com aviso no Discord). O 4º parâmetro (next) é
    // obrigatório para o Express reconhecer isto como handler de erro.
    server.use((error: unknown, req: Request, res: Response, _next: NextFunction): void => {
        if (error instanceof ValidationError) {
            res.status(422).json({ detail: error.message, errors: error.details });
            return;
        }
        if (isBadJson(error)) {
            const message = error instanceof Error ? error.message : 'JSON inválido.';
            res.status(400).json({ detail: message });
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

/** Erro do body-parser do Express: JSON malformado (400) ou corpo grande (413). */
function isBadJson(error: unknown): boolean {
    if (typeof error !== 'object' || error === null) {
        return false;
    }
    const { type, status } = error as { type?: unknown; status?: unknown };
    return type === 'entity.parse.failed' || type === 'entity.too.large' || status === 400;
}

main();
