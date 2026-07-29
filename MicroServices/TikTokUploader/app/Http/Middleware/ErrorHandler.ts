import type { NextFunction, Request, Response } from 'express';
import multer from 'multer';

import { logger } from '@/Config/Logger';
import { LoginFailedError } from '@/Exceptions/LoginFailedError';
import { ValidationError } from '@/Exceptions/ValidationError';
import { discord } from '@/Services/Notifications/Discord';

// Falhas de parsing do body multipart (busboy) — sempre culpa do request.
const MULTIPART_PARSE_ERRORS = ['part header', 'Multipart', 'Boundary', 'Unexpected end of form'];

// Request malformado: JSON inválido, body grande demais ou multipart quebrado.
function isBadRequest(error: unknown): boolean {
    if (error instanceof multer.MulterError) {
        return true;
    }

    if (typeof error !== 'object' || error === null) {
        return false;
    }

    const { type, status, message } = error as {
        type?: unknown;
        status?: unknown;
        message?: unknown;
    };

    if (['entity.parse.failed', 'entity.too.large'].includes(type as string) || status === 400) {
        return true;
    }

    return typeof message === 'string' && MULTIPART_PARSE_ERRORS.some((m) => message.includes(m));
}

export default (error: unknown, req: Request, res: Response, _next: NextFunction): void => {
    const message = error instanceof Error ? error.message : String(error);
    const details = error instanceof ValidationError ? ` ${JSON.stringify(error.details)}` : '';
    logger.error(`Erro na rota ${req.method} ${req.url}: ${message}${details}`);

    // Erros de cliente (422/401/400) não vão pro Discord — só falhas reais.
    const clientError =
        error instanceof ValidationError ||
        error instanceof LoginFailedError ||
        isBadRequest(error);
    if (!clientError) {
        void discord.notifyError(`rota ${req.method} ${req.url}`, error);
    }

    if (error instanceof ValidationError) {
        res.status(422).json({ detail: error.message, errors: error.details });
        return;
    }

    if (error instanceof LoginFailedError) {
        res.status(401).json({ detail: error.message });
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
