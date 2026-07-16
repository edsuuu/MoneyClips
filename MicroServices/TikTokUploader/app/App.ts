import express, { type Express } from 'express';
import morgan from 'morgan';

import { settings } from '@/Config/Env';
import { logger } from '@/Config/Logger';
import { discord } from '@/Services/Notifications/Discord';
import { initObservability } from '@/Services/Observability/RemoteObservability';

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

    private middlewares(): void {
        this.app.use(morgan('[:datetime] Request :method :url', { immediate: true }));
        this.app.use(morgan('[:datetime] Response :method :url :status :response-time ms'));
        this.app.use(express.json({ limit: MAX_BODY_BYTES }));
        this.app.use(this.routers.getRouter());
        this.app.use(notFound);
        this.app.use(errorHandler);
    }

    public server(port: number): void {
        initObservability('tiktok-uploader');

        const httpServer = this.app.listen(port, () => {
            logger.info(`API do tiktok-uploader ouvindo em http://0.0.0.0:${port}`);

            if (settings.dryRun) {
                logger.warn('DRY_RUN ativo — modo teste: o vídeo NÃO será postado no TikTok.');
            }
        });

        // Upload é síncrono e pode levar minutos (processamento + verificação do TikTok).
        // Sem esses limites o Node derruba a conexão no meio e o cliente (Insomnia) toma timeout.
        httpServer.requestTimeout = 0;
        httpServer.timeout = 0;
        httpServer.keepAliveTimeout = 10 * 60_000;

        const shutdown = (): void => {
            logger.info('Encerrando API...');
            httpServer.close(() => process.exit(0));
        };
        process.on('SIGINT', shutdown);
        process.on('SIGTERM', shutdown);

        process.on('unhandledRejection', (reason: unknown) => {
            logger.error(
                `unhandledRejection: ${reason instanceof Error ? reason.message : String(reason)}`,
            );

            void discord.notifyError('unhandledRejection', reason);
        });

        process.on('uncaughtException', (error: Error) => {
            logger.error(`uncaughtException: ${error.message}`);

            void discord.notifyError('uncaughtException', error);
        });
    }
}

new App().server(settings.apiPort);
