import type { Request, Response } from 'express';

export class NotFound {
    public handle = (_req: Request, res: Response): void => {
        res.status(404).json({ detail: 'rota não encontrada' });
    };
}

export const notFound = new NotFound();
