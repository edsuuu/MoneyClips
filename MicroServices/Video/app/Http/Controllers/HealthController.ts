import type { Request, Response } from 'express';

import { settings } from '@/Config/Env';
import { CaptionQueueService } from '@/Services/Caption/CaptionQueueService';
import { PackageQueueService } from '@/Services/PackageQueueService';
import { ReencodeQueueService } from '@/Services/ReencodeQueueService';

export class HealthController {
    public constructor(
        private readonly queue: PackageQueueService,
        private readonly reencodes: ReencodeQueueService,
        private readonly captions: CaptionQueueService,
    ) {}

    public health(_req: Request, res: Response): void {
        res.json({
            status: 'ok',
            encoder: settings.encoder,
            segment_seconds: settings.segmentSeconds,
            queued: this.queue.size(),
            reencode_enabled: settings.reencodeEnabled,
            threshold_kbps: settings.reencodeBitrateThresholdKbps,
            reencodes_running: this.reencodes.size(),
            captions_queued: this.captions.size(),
            transcriber_url: settings.transcriberUrl,
        });
    }
}
