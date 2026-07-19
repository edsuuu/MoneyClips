import express, { type Express } from 'express';
import morgan from 'morgan';

import { settings } from '@/Config/Env';
import { logger } from '@/Config/Logger';
import { packageQueue } from '@/Services/PackageQueueService';
import { initObservability } from '@/Services/RemoteObservability';

import errorHandler from './Http/Middleware/ErrorHandler';
import notFound from './Http/Middleware/NotFound';
import { Routers } from './Http/Routers';

const MAX_BODY_BYTES = 1_000_000;

morgan.token('datetime', () => {
    const now = new Date();
    const pad = (value: number): string => String(value).padStart(2, '0');

    return (
        `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()} ` +
        `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`
    );
});

export class App {
    public readonly app: Express;

    public constructor(private readonly routers: Routers = new Routers()) {
        this.app = express();
        this.middlewares();
    }

    public server(port: number): void {
        initObservability('hls');

        const httpServer = this.app.listen(port, () => {
            logger.info(`API do hls ouvindo em http://0.0.0.0:${String(port)}`);
            logger.info(
                `encoder=${settings.encoder} | segmento=${String(settings.segmentSeconds)}s | bucket=${settings.storageBucket}`,
            );
        });

        // POST /package responde 202 na hora (empacotamento roda em background);
        // o requestTimeout só cobre o parse do JSON, que é minúsculo.
        httpServer.requestTimeout = 30_000;

        const shutdown = (): void => {
            logger.info(`Encerrando API (${String(packageQueue.size())} job(s) na fila)...`);
            httpServer.close(() => process.exit(0));
        };
        process.on('SIGINT', shutdown);
        process.on('SIGTERM', shutdown);

        process.on('unhandledRejection', (reason: unknown) => {
            logger.error(
                `unhandledRejection: ${reason instanceof Error ? reason.message : String(reason)}`,
            );
        });

        process.on('uncaughtException', (error: Error) => {
            logger.error(`uncaughtException: ${error.message}`);
        });
    }

    private middlewares(): void {
        this.app.use(morgan('[:datetime] Request :method :url', { immediate: true }));
        this.app.use(morgan('[:datetime] Response :method :url :status :response-time ms'));
        this.app.use(express.json({ limit: MAX_BODY_BYTES }));
        this.app.use(this.routers.getRouter());
        this.app.use(notFound);
        this.app.use(errorHandler);
    }
}

new App().server(settings.apiPort);
