import type { Request, Response } from 'express';

import { settings } from '@/Config/Env';

export class HealthController {
    public health(_req: Request, res: Response): void {
        res.json({ status: 'ok', dry_run: settings.dryRun });
    }
}
