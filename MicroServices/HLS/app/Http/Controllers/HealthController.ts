import type { Request, Response } from 'express';

import { settings } from '@/Config/Env';
import { PackageQueueService } from '@/Services/PackageQueueService';

export class HealthController {
    public constructor(private readonly queue: PackageQueueService) {}

    public health(_req: Request, res: Response): void {
        res.json({
            status: 'ok',
            encoder: settings.encoder,
            segment_seconds: settings.segmentSeconds,
            queued: this.queue.size(),
        });
    }
}
