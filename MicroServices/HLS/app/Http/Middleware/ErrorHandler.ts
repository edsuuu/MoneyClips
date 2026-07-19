import type { NextFunction, Request, Response } from 'express';

import { logger } from '@/Config/Logger';
import { ValidationError } from '@/Exceptions/ValidationError';

function isBadRequest(error: unknown): boolean {
    if (typeof error !== 'object' || error === null) {
        return false;
    }

    const { type, status } = error as { type?: unknown; status?: unknown };

    return type === 'entity.parse.failed' || type === 'entity.too.large' || status === 400;
}

export default (error: unknown, req: Request, res: Response, _next: NextFunction): void => {
    const message = error instanceof Error ? error.message : String(error);
    const details = error instanceof ValidationError ? ` ${JSON.stringify(error.details)}` : '';
    logger.error(`Erro na rota ${req.method} ${req.url}: ${message}${details}`);

    if (error instanceof ValidationError) {
        res.status(422).json({ detail: error.message, errors: error.details });
        return;
    }

    if (isBadRequest(error)) {
        res.status(400).json({ detail: message });
        return;
    }

    if (!res.headersSent) {
        res.status(500).json({ detail: 'erro interno' });
    }
};
