/**
 * Transporte HTTP do microserviço (porta API_PORT). Só traduz HTTP para a
 * camada de aplicação (src/app) — sem regra de negócio aqui.
 *
 *   npm start   (ou: npm run api)
 *
 * Rotas:
 *   GET  /health   — heartbeat (tamanho da fila, DRY_RUN)
 *   GET  /session  — checagem leve do cookie/sessão da conta (sem navegador)
 *   POST /session  — injeta uma sessão (cookies exportados) gerada fora do serviço
 *   POST /login    — login explícito (abre o navegador, loga e grava os cookies)
 *   POST /posts    — enfileira um post; responde 202 e avisa no webhook ao fim
 */

import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';

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
function authorized(req: IncomingMessage): boolean {
    if (!settings.apiToken) {
        return true;
    }
    const auth = req.headers['authorization'];
    const bearer = typeof auth === 'string' && auth.startsWith('Bearer ') ? auth.slice(7) : '';
    const headerToken = bearer || req.headers['x-api-token'];
    return headerToken === settings.apiToken;
}

function sendJson(res: ServerResponse, statusCode: number, payload: unknown): void {
    const body = JSON.stringify(payload);
    res.writeHead(statusCode, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Length': Buffer.byteLength(body),
    });
    res.end(body);
}

async function readJsonBody(req: IncomingMessage): Promise<unknown> {
    const chunks: Buffer[] = [];
    let received = 0;

    for await (const chunk of req) {
        const buffer = chunk as Buffer;
        received += buffer.length;
        if (received > MAX_BODY_BYTES) {
            throw new Error('Corpo da requisição grande demais.');
        }
        chunks.push(buffer);
    }

    const raw = Buffer.concat(chunks).toString('utf-8');
    return raw.trim() === '' ? {} : (JSON.parse(raw) as unknown);
}

/**
 * Lê o corpo JSON, passa para o produtor e responde com `successStatus`. Mapeia
 * ValidationError -> 422 e JSON malformado -> 400. Compartilhado por /posts,
 * /login e /session.
 */
async function handleJsonPost(
    req: IncomingMessage,
    res: ServerResponse,
    successStatus: number,
    produce: (body: unknown) => unknown | Promise<unknown>,
): Promise<void> {
    let body: unknown;
    try {
        body = await readJsonBody(req);
    } catch (error) {
        sendJson(res, 400, { detail: error instanceof Error ? error.message : 'JSON inválido.' });
        return;
    }

    try {
        sendJson(res, successStatus, await produce(body));
    } catch (error) {
        if (error instanceof ValidationError) {
            sendJson(res, 422, { detail: error.message, errors: error.details });
            return;
        }
        throw error;
    }
}

async function route(req: IncomingMessage, res: ServerResponse): Promise<void> {
    const url = new URL(req.url ?? '/', 'http://localhost');
    const path = url.pathname.replace(/\/+$/, '') || '/';

    // /health fica aberto (healthcheck do container); o resto exige token.
    if (req.method === 'GET' && path === '/health') {
        sendJson(res, 200, app.health());
        return;
    }

    if (!authorized(req)) {
        sendJson(res, 401, { detail: 'não autorizado' });
        return;
    }

    if (req.method === 'GET' && path === '/session') {
        sendJson(res, 200, await app.session());
        return;
    }
    if (req.method === 'POST' && path === '/session') {
        await handleJsonPost(req, res, 200, (body) => app.injectSession(body));
        return;
    }
    if (req.method === 'POST' && path === '/login') {
        await handleJsonPost(req, res, 200, (body) => app.login(body));
        return;
    }
    if (req.method === 'POST' && path === '/posts') {
        await handleJsonPost(req, res, 202, (body) => app.createPost(body));
        return;
    }
    sendJson(res, 404, { detail: 'rota não encontrada' });
}

function main(): void {
    const server = createServer((req, res) => {
        route(req, res).catch(async (error: unknown) => {
            const message = error instanceof Error ? error.message : String(error);
            logger.error(`Erro não tratado na rota ${req.method} ${req.url}: ${message}`);
            await sendDiscordError(`rota ${req.method} ${req.url}`, error);
            if (!res.headersSent) {
                sendJson(res, 500, { detail: 'erro interno' });
            }
        });
    });

    server.listen(settings.apiPort, () => {
        logger.info(`API do tiktok-uploader ouvindo em http://0.0.0.0:${settings.apiPort}`);
        logger.info(`DRY_RUN=${settings.dryRun} | conta: ${settings.tiktokAccountName}`);
    });

    const shutdown = (): void => {
        logger.info('Encerrando API...');
        server.close(() => process.exit(0));
    };
    process.on('SIGINT', shutdown);
    process.on('SIGTERM', shutdown);
}

main();
