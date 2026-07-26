import { randomUUID } from 'node:crypto';

import { Logger } from '@/Config/Logger';

/**
 * Fila serial em promise-chain (1 job por vez — ffmpeg monopoliza CPU/GPU),
 * compartilhada pelas rotas assíncronas que trabalham por chave de storage.
 */
export abstract class SerialQueueService<TJob extends { uuid: string }> extends Logger {
    private chain: Promise<unknown> = Promise.resolve();
    private pending = 0;

    public size(): number {
        return this.pending;
    }

    public enqueue(input: Omit<TJob, 'uuid'>): string {
        const job = { uuid: randomUUID(), ...input } as TJob;
        this.pending += 1;

        const run = async (): Promise<void> => {
            try {
                await this.process(job);
            } finally {
                this.pending -= 1;
            }
        };

        this.chain = this.chain.then(run, run).catch(() => undefined);

        return job.uuid;
    }

    protected abstract process(job: TJob): Promise<void>;
}
