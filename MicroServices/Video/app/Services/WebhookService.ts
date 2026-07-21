import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';
import { Sleep } from '@/Utils/Sleep';

export interface WebhookPayload {
    uuid: string;
    status: 'done' | 'failed' | 'rejected' | 'progress';
    progress?: number;
    error?: string;
    duration_seconds?: number;
    width?: number;
    height?: number;
    hash?: string;
    renditions?: string[];
    poster?: boolean;
    files?: Record<string, boolean>;
}

/**
 * Entrega do desfecho ao Laravel. O Laravel pode estar reiniciando quando um
 * job de horas termina, e perder o webhook deixaria o vídeo preso em
 * "packaging" — daí o backoff (0s/1s/5s/15s), igual ao download-shorts.
 */
export class WebhookService extends Logger {
    private static readonly RETRY_DELAYS_MS = [0, 1_000, 5_000, 15_000];
    private static readonly TIMEOUT_MS = 30_000;

    public async send(url: string, payload: WebhookPayload): Promise<boolean> {
        for (let attempt = 1; attempt <= WebhookService.RETRY_DELAYS_MS.length; attempt += 1) {
            const delay = WebhookService.RETRY_DELAYS_MS[attempt - 1] ?? 0;

            if (delay > 0) {
                await Sleep.for(delay);
            }

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: this.headers(),
                    body: JSON.stringify(payload),
                    signal: AbortSignal.timeout(WebhookService.TIMEOUT_MS),
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${String(response.status)}`);
                }

                return true;
            } catch (error) {
                this.warn(
                    `Webhook falhou (tentativa ${String(attempt)}/${String(WebhookService.RETRY_DELAYS_MS.length)}): ` +
                        `${error instanceof Error ? error.message : String(error)}`,
                );
            }
        }

        this.error(
            `Webhook desistiu após ${String(WebhookService.RETRY_DELAYS_MS.length)} tentativas: ${url}`,
        );

        return false;
    }

    public sendProgress(url: string, uuid: string, progress: number): void {
        // Progresso é best-effort: uma atualização perdida não afeta o desfecho,
        // então não gasta retries nem segura o encode.
        void fetch(url, {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify({ uuid, status: 'progress', progress }),
            signal: AbortSignal.timeout(5_000),
        }).catch(() => undefined);
    }

    private headers(): Record<string, string> {
        const headers: Record<string, string> = { 'Content-Type': 'application/json' };

        if (settings.observabilityToken !== '') {
            headers['X-Observability-Token'] = settings.observabilityToken;
        }

        return headers;
    }
}
