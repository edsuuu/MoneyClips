import type { Request, Response } from 'express';

import { ValidationError } from '@/Exceptions/ValidationError';
import { SessionService } from '@/Services/SessionService';
import type { Cookie } from '@/Types/DomainType';

import type { LoginRequest } from '../Requests/LoginRequest';
import type { SessionRequest } from '../Requests/SessionRequest';

export class AuthController {
    public constructor(private readonly service: SessionService) {}

    public async session(req: Request, res: Response): Promise<void> {
        const body = (req.body ?? {}) as SessionRequest;

        if (!Array.isArray(body.cookies) || body.cookies.length === 0) {
            throw new ValidationError({ cookies: 'obrigatório: array não vazio de cookies' });
        }

        const session = await this.service.checkCookies(body.cookies as Cookie[]);

        res.status(200).json(session);
    }

    public async login(req: Request, res: Response): Promise<void> {
        const { email, password, keep_open, qr_code } = (req.body ?? {}) as LoginRequest;

        if (qr_code === true) {
            const qrSession = await this.service.loginWithQrCode(keep_open === true);

            res.status(200).json(qrSession);

            return;
        }

        if (!email || !password) {
            throw new ValidationError({ email: Boolean(email), password: Boolean(password) });
        }

        const session = await this.service.login(email, password, keep_open === true);

        res.status(200).json(session);
    }
}
