import type { Request, Response } from 'express';
import { unlink } from 'node:fs/promises';

import { Logger } from '@/Config/Logger';
import { ReencodeQueueService } from '@/Services/ReencodeQueueService';

export class ReencodeController extends Logger {
    public constructor(private readonly queue: ReencodeQueueService) {
        super();
    }

    /**
     * SÍNCRONO (ao contrário do /package): o Laravel envia o binário por
     * multipart e recebe o vídeo recodificado na própria resposta —
     * 200 binário (X-Reencode: completed) ou 200 {status: "skipped"}.
     */
    public async create(req: Request, res: Response): Promise<void> {
        const uploaded = req.file?.path;

        if (uploaded === undefined) {
            res.status(422).json({ detail: 'campo "video" (arquivo) é obrigatório' });

            return;
        }

        const body = (req.body ?? {}) as { video_id?: unknown };
        const videoId = typeof body.video_id === 'string' ? body.video_id : '';

        try {
            const result = await this.queue.run(uploaded, videoId);

            if (!result.reencoded) {
                res.json({ status: 'skipped', reencoded: false });

                return;
            }

            res.setHeader('X-Reencode', 'completed');
            res.sendFile(result.outputPath, (error) => {
                if (error) {
                    this.error(`Falha ao enviar o vídeo recodificado: ${error.message}`);
                }

                void this.removeQuietly(result.outputPath);
            });
        } finally {
            await this.removeQuietly(uploaded);
        }
    }

    private async removeQuietly(path: string): Promise<void> {
        try {
            await unlink(path);
        } catch {
            // arquivo já removido/ausente — nada a fazer
        }
    }
}
