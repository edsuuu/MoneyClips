/**
 * Client do único serviço que continua em Python: a transcrição
 * (faster-whisper/CUDA). Manda o wav e recebe o transcript com timestamps por
 * palavra — nada mais.
 */

import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';
import type { Transcript } from '@/Services/Caption/SubtitleBuilder';

export class TranscriberClient extends Logger {
    public async transcribe(audioPath: string): Promise<Transcript> {
        const body = new FormData();
        const audio = await readFile(audioPath);
        body.append('audio', new Blob([audio]), basename(audioPath));

        this.info(`[Caption] Transcrevendo em ${settings.transcriberUrl}`);

        const response = await fetch(`${settings.transcriberUrl.replace(/\/+$/u, '')}/transcribe`, {
            method: 'POST',
            body,
            signal: AbortSignal.timeout(settings.transcriberTimeoutMs),
        });

        if (!response.ok) {
            throw new Error(
                `Transcritor respondeu ${String(response.status)}: ${(await response.text()).slice(0, 300)}`,
            );
        }

        return (await response.json()) as Transcript;
    }
}
