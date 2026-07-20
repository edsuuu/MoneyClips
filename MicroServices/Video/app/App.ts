import express, { type Express } from 'express';
import morgan from 'morgan';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';
import { packageQueue } from '@/Services/PackageQueueService';
import { observability } from '@/Services/RemoteObservability';

import { errorHandler } from './Http/Middleware/ErrorHandler';
import { notFound } from './Http/Middleware/NotFound';
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

export class App extends Logger {
    public readonly app: Express;

    public constructor(private readonly routers: Routers = new Routers()) {
        super();
        this.app = express();
        this.middlewares();
    }

    public server(port: number): void {
        observability.start('video');

        const httpServer = this.app.listen(port, () => {
            this.info(`API do video ouvindo em http://0.0.0.0:${String(port)}`);
            this.info(
                `encoder=${settings.encoder} | segmento=${String(settings.segmentSeconds)}s | bucket=${settings.storageBucket}`,
            );
            this.info(
                `reencode=${String(settings.reencodeEnabled)} | limiar=${String(settings.reencodeBitrateThresholdKbps)} kbps`,
            );
        });

        // POST /reencode é SÍNCRONO: o upload de até 1 GB mais o ffmpeg podem
        // levar minutos, e o requestTimeout padrão do Node (5 min) derrubaria a
        // conexão no meio. Alinhado ao REENCODE_TIMEOUT do Laravel (900s).
        httpServer.requestTimeout = 900_000;

        const shutdown = (): void => {
            this.info(`Encerrando API (${String(packageQueue.size())} job(s) na fila)...`);
            httpServer.close(() => process.exit(0));
        };
        process.on('SIGINT', shutdown);
        process.on('SIGTERM', shutdown);

        process.on('unhandledRejection', (reason: unknown) => {
            this.error(
                `unhandledRejection: ${reason instanceof Error ? reason.message : String(reason)}`,
            );
        });

        process.on('uncaughtException', (error: Error) => {
            this.error(`uncaughtException: ${error.message}`);
        });
    }

    private middlewares(): void {
        this.app.use(morgan('[:datetime] Request :method :url', { immediate: true }));
        this.app.use(morgan('[:datetime] Response :method :url :status :response-time ms'));
        this.app.use(express.json({ limit: MAX_BODY_BYTES }));
        this.app.use(this.routers.getRouter());
        this.app.use(notFound.handle);
        this.app.use(errorHandler.handle);
    }
}

new App().server(settings.apiPort);
