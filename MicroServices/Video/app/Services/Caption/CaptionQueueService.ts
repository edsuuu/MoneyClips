/**
 * Fila dos jobs de legenda — serial, porque cada job monopoliza GPU (whisper +
 * nvenc). É a terceira fila do serviço, independente de HLS e reencode.
 *
 * Com legenda o job roda em duas fases: `enqueue` (fase 1, até submeter a
 * transcrição) e `enqueueResume`/`enqueueResumeFailure` (fase 2, disparadas
 * pelo webhook do transcritor). Ambas entram na MESMA cadeia serial.
 */

import { Logger } from '@/Config/Logger';
import { CaptionJobService } from '@/Services/Caption/CaptionJobService';
import { CaptionOptionsData } from '@/Services/Caption/CaptionOptionsData';
import type { Transcript } from '@/Services/Caption/SubtitleBuilder';

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
        this.schedule(() => this.jobs.process(uuid, options));
    }

    public enqueueResume(uuid: string, transcript: Transcript): void {
        this.schedule(() => this.jobs.resume(uuid, transcript));
    }

    public enqueueResumeFailure(uuid: string, message: string): void {
        this.schedule(() => this.jobs.failFromTranscriber(uuid, message));
    }

    private schedule(run: () => Promise<void>): void {
        this.queued += 1;

        this.chain = this.chain
            .then(run, run)
            .catch(() => {
                // erro já logado dentro do job
            })
            .finally(() => {
                this.queued -= 1;
            });
    }
}

export const captionQueue = new CaptionQueueService();
