/**
 * Observabilidade remota (OBSERVABILITY.md): registra um sink no logger para
 * também empilhar as linhas num buffer e enviá-las em lote ao Laravel
 * (POST /api/observability/logs, flush a cada 2s ou 20 linhas), além de um
 * heartbeat a cada 30s (POST /api/observability/heartbeat).
 *
 * Regra de ouro: FIRE-AND-FORGET. Timeout curto, erro descartado — o envio de
 * log nunca pode derrubar ou atrasar o serviço. `pm2 logs` continua igual
 * (o console permanece como saída primária).
 */

import { hostname } from 'node:os';

import { logger, type LogLevel } from '@/Config/Logger';

interface LogEntry {
    level: string;
    message: string;
    context: null;
    logged_at: string;
}

export class RemoteObservability {
    private static readonly FLUSH_INTERVAL_MS = 2_000;
    private static readonly FLUSH_MAX_ENTRIES = 20;
    private static readonly HEARTBEAT_INTERVAL_MS = 30_000;
    private static readonly REQUEST_TIMEOUT_MS = 3_000;
    private static readonly BUFFER_HARD_CAP = 500;

    private buffer: LogEntry[] = [];
    private baseUrl = '';
    private token = '';
    private service = '';

    /**
     * Liga o push remoto. Sem OBSERVABILITY_URL/TOKEN configurados, não faz nada
     * (log local continua normal).
     */
    public start(serviceName: string): void {
        this.baseUrl = (process.env['OBSERVABILITY_URL'] ?? '').replace(/\/+$/, '');
        this.token = process.env['OBSERVABILITY_TOKEN'] ?? '';
        this.service = process.env['SERVICE_NAME'] ?? serviceName;

        if (this.baseUrl === '' || this.token === '') {
            logger.warn(
                '[Observability] OBSERVABILITY_URL/OBSERVABILITY_TOKEN não configurados — push remoto desativado.',
            );

            return;
        }

        logger.addSink((level, message) => {
            this.collect(level, message);
        });

        setInterval(() => {
            this.flush();
        }, RemoteObservability.FLUSH_INTERVAL_MS).unref();

        this.heartbeat();
        setInterval(() => {
            this.heartbeat();
        }, RemoteObservability.HEARTBEAT_INTERVAL_MS).unref();

        logger.info(`[Observability] Push remoto ligado (${this.service} → ${this.baseUrl}).`);
    }

    private collect(level: LogLevel, message: string): void {
        this.buffer.push({
            level,
            message,
            context: null,
            logged_at: new Date().toISOString(),
        });

        if (this.buffer.length >= RemoteObservability.BUFFER_HARD_CAP) {
            // backpressure: descarta os antigos
            this.buffer = this.buffer.slice(-RemoteObservability.FLUSH_MAX_ENTRIES);

            return;
        }

        if (this.buffer.length >= RemoteObservability.FLUSH_MAX_ENTRIES) {
            this.flush();
        }
    }

    private flush(): void {
        if (this.buffer.length === 0) {
            return;
        }

        const entries = this.buffer;
        this.buffer = [];
        this.post('/logs', { service: this.service, hostname: hostname(), entries });
    }

    private heartbeat(): void {
        this.post('/heartbeat', {
            service: this.service,
            hostname: hostname(),
            uptime_seconds: Math.round(process.uptime()),
            memory_mb: Math.round(process.memoryUsage().rss / 1024 / 1024),
        });
    }

    private post(path: string, body: unknown): void {
        void fetch(`${this.baseUrl}${path}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Observability-Token': this.token,
            },
            body: JSON.stringify(body),
            signal: AbortSignal.timeout(RemoteObservability.REQUEST_TIMEOUT_MS),
        }).catch(() => undefined); // Laravel fora do ar: descarta e segue
    }
}

export const observability = new RemoteObservability();
