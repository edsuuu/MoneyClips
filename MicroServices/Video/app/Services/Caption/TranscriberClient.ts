/**
 * Client do único serviço que continua em Python: a transcrição
 * (faster-whisper). Submete o wav ao endpoint assíncrono `/transcriptions` e
 * recebe 202 na hora — o transcript volta por webhook (`webhookUrl`), que o
 * serviço processa em `CaptionJobService.resume`.
 */

import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';

export class TranscriberClient extends Logger {
    public async submit(uuid: string, audioPath: string, webhookUrl: string): Promise<void> {
        const body = new FormData();
        const audio = await readFile(audioPath);
        body.append('audio', new Blob([audio]), basename(audioPath));
        body.append('uuid', uuid);
        body.append('webhook_url', webhookUrl);

        this.info(`[Caption] Submetendo transcrição de ${uuid} em ${settings.transcriberUrl}`);

        const response = await fetch(
            `${settings.transcriberUrl.replace(/\/+$/u, '')}/transcriptions`,
            {
                method: 'POST',
                body,
                signal: AbortSignal.timeout(settings.transcriberTimeoutMs),
            },
        );

        if (!response.ok) {
            throw new Error(
                `Transcritor respondeu ${String(response.status)}: ${(await response.text()).slice(0, 300)}`,
            );
        }
    }
}
