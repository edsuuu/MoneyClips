/**
 * Fila dos jobs de legenda — serial, porque cada job monopoliza GPU (whisper +
 * nvenc). É a terceira fila do serviço, independente de HLS e reencode.
 */

import { Logger } from '@/Config/Logger';
import { CaptionJobService } from '@/Services/Caption/CaptionJobService';
import { CaptionOptionsData } from '@/Services/Caption/CaptionOptionsData';

export class CaptionQueueService extends Logger {
    // ponytail: lock global por promise-chain — 1 job por vez. Se um dia a
    // máquina tiver GPU sobrando, o upgrade é uma fila com concorrência N.
    private chain: Promise<unknown> = Promise.resolve();
    private queued = 0;

    public constructor(private readonly jobs: CaptionJobService = new CaptionJobService()) {
        super();
    }

    public size(): number {
        return this.queued;
    }

    public enqueue(uuid: string, options: CaptionOptionsData): void {
        this.queued += 1;

        const run = async (): Promise<void> => {
            await this.jobs.process(uuid, options);
        };

        this.chain = this.chain
            .then(run, run)
            .catch(() => undefined)
            .finally(() => {
                this.queued -= 1;
            });
    }
}

export const captionQueue = new CaptionQueueService();
