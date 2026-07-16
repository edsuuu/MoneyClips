import { logger } from '@/Config/Logger';
import { sleep } from '@/Utils/Sleep';

// Mesmo padrão do download-shorts: 3 re-tentativas com backoff; se o Laravel
// estiver fora do ar, loga e desiste — o webhook nunca derruba a fila.
const RETRY_DELAYS_MS = [0, 1_000, 5_000, 15_000];
const TIMEOUT_MS = 30_000;

export async function sendWebhook(url: string, payload: unknown): Promise<boolean> {
    for (let attempt = 1; attempt <= RETRY_DELAYS_MS.length; attempt += 1) {
        const delay = RETRY_DELAYS_MS[attempt - 1] ?? 0;

        if (delay > 0) {
            await sleep(delay);
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                signal: AbortSignal.timeout(TIMEOUT_MS),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            logger.info(`Webhook aceito (${response.status}) em ${url}`);

            return true;
        } catch (error) {
            logger.warn(
                `Webhook falhou (tentativa ${attempt}/${RETRY_DELAYS_MS.length}): ` +
                    `${error instanceof Error ? error.message : String(error)}`,
            );
        }
    }

    logger.error(`Webhook desistiu após ${RETRY_DELAYS_MS.length} tentativas: ${url}`);

    return false;
}
