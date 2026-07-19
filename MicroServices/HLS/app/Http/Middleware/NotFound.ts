import type { Request, Response } from 'express';

export default (_req: Request, res: Response): void => {
    res.status(404).json({ detail: 'rota não encontrada' });
};
