import type { NextFunction, Request, Response } from 'express';

export function asyncHandler(
    handler: (req: Request, res: Response) => Promise<void>,
): (req: Request, res: Response, next: NextFunction) => void {
    return (req, res, next) => {
        handler(req, res).catch(next);
    };
}
