/**
 * Serializa os reencodes: ffmpeg é pesado e o /reencode é síncrono (o Laravel
 * segura a conexão até receber o binário). Fila própria — um empacotamento HLS
 * longo (PackageQueueService) não pode fazer o Laravel esperar aqui.
 */

import { Logger } from '@/Config/Logger';
import { VideoReencoder } from '@/Services/Video/VideoReencoder';

export interface ReencodeResult {
    reencoded: boolean;
    outputPath: string;
}

export class ReencodeQueueService extends Logger {
    // ponytail: lock global via promise-chain — 1 ffmpeg por vez. Se um dia
    // precisar de paralelismo, o upgrade é uma fila com concorrência N.
    private chain: Promise<unknown> = Promise.resolve();
    private running = 0;

    public constructor(private readonly reencoder: VideoReencoder = new VideoReencoder()) {
        super();
    }

    public size(): number {
        return this.running;
    }

    /** Recodifica (serializado). Nunca lança por causa do reencode em si. */
    public run(videoPath: string, videoId: string): Promise<ReencodeResult> {
        this.running += 1;

        const task = async (): Promise<ReencodeResult> => {
            this.info(`Reencode solicitado: videoId=${videoId || 'sem-id'} arquivo=${videoPath}`);
            const outputPath = await this.reencoder.reencodeIfNeeded(
                videoPath,
                videoId || 'sem-id',
            );

            return { reencoded: outputPath !== videoPath, outputPath };
        };

        const next = this.chain.then(task, task).finally(() => {
            this.running -= 1;
        });
        this.chain = next.catch(() => {
            // erro já logado dentro do job
        });

        return next;
    }
}

export const reencodeQueue = new ReencodeQueueService();
