import type { NextFunction, Request, Response } from 'express';
import multer from 'multer';

import { logger } from '@/Config/Logger';
import { ValidationError } from '@/Exceptions/ValidationError';
import { discord, DiscordService } from '@/Services/DiscordService';

export class ErrorHandler {
    public constructor(private readonly notifier: DiscordService = discord) {}

    // O 4º parâmetro (next) é obrigatório para o Express reconhecer isto como
    // handler de erro, mesmo sem uso.
    public handle = (error: unknown, req: Request, res: Response, _next: NextFunction): void => {
        const message = error instanceof Error ? error.message : String(error);
        const details = error instanceof ValidationError ? ` ${JSON.stringify(error.details)}` : '';
        logger.error(`Erro na rota ${req.method} ${req.url}: ${message}${details}`);

        if (error instanceof ValidationError) {
            res.status(422).json({ detail: error.message, errors: error.details });

            return;
        }

        if (error instanceof multer.MulterError) {
            res.status(error.code === 'LIMIT_FILE_SIZE' ? 413 : 400).json({ detail: message });

            return;
        }

        if (this.isBadRequest(error)) {
            res.status(400).json({ detail: message });

            return;
        }

        void this.notifier.sendError(`rota ${req.method} ${req.url}`, error);

        if (!res.headersSent) {
            res.status(500).json({ detail: 'erro interno' });
        }
    };

    private isBadRequest(error: unknown): boolean {
        if (typeof error !== 'object' || error === null) {
            return false;
        }

        const { type, status } = error as { type?: unknown; status?: unknown };

        return type === 'entity.parse.failed' || type === 'entity.too.large' || status === 400;
    }
}

export const errorHandler = new ErrorHandler();
