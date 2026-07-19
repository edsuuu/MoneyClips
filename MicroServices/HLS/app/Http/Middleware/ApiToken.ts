import type { NextFunction, Request, Response } from 'express';

import { settings } from '@/Config/Env';

/**
 * API_TOKEN setado no .env = exige `Authorization: Bearer <token>` (o Laravel
 * envia via HLS_API_TOKEN); vazio = aberto (dev local).
 */
export function apiToken(req: Request, res: Response, next: NextFunction): void {
    if (settings.apiToken === '') {
        next();

        return;
    }

    if (req.headers.authorization === `Bearer ${settings.apiToken}`) {
        next();

        return;
    }

    res.status(401).json({ detail: 'Token inválido — envie Authorization: Bearer <API_TOKEN>.' });
}
