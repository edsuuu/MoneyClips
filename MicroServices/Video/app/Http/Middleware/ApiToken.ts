import type { NextFunction, Request, Response } from 'express';

import { settings } from '@/Config/Env';

/**
 * API_TOKEN setado no .env = exige o token via `Authorization: Bearer <token>`
 * (HLSPackager) ou `X-API-Token: <token>` (ReencodeService); vazio = aberto
 * (dev local).
 */
export class ApiToken {
    public handle = (req: Request, res: Response, next: NextFunction): void => {
        if (settings.apiToken === '') {
            next();

            return;
        }

        const bearer = req.headers.authorization === `Bearer ${settings.apiToken}`;
        const apiHeader = req.headers['x-api-token'] === settings.apiToken;

        if (bearer || apiHeader) {
            next();

            return;
        }

        res.status(401).json({
            detail: 'Token inválido — envie Authorization: Bearer <API_TOKEN> ou X-API-Token.',
        });
    };
}

export const apiToken = new ApiToken();
