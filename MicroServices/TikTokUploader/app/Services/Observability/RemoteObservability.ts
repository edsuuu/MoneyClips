/**
 * Observabilidade remota: decora o logger do console para
 * também empilhar as linhas num buffer e enviá-las em lote ao Laravel
 * (POST /api/observability/logs, flush a cada 2s ou 20 linhas), além de um
 * heartbeat a cada 30s (POST /api/observability/heartbeat).
 *
 * Regra de ouro: FIRE-AND-FORGET. Timeout curto, erro descartado — o envio de
 * log nunca pode derrubar ou atrasar o serviço. `pm2 logs` continua igual
 * (o console permanece como saída primária).
 */

import { hostname } from 'node:os';

import { logger } from '@/Config/Logger';

interface LogEntry {
    level: string;
    message: string;
    context: null;
    logged_at: string;
}

const FLUSH_INTERVAL_MS = 2_000;
const FLUSH_MAX_ENTRIES = 20;
const HEARTBEAT_INTERVAL_MS = 30_000;
const REQUEST_TIMEOUT_MS = 3_000;
const BUFFER_HARD_CAP = 500;

let buffer: LogEntry[] = [];
let baseUrl = '';
let token = '';
let service = '';

function post(path: string, body: unknown): void {
    void fetch(`${baseUrl}${path}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Observability-Token': token,
        },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
    }).catch(() => undefined); // Laravel fora do ar: descarta e segue
}

function flush(): void {
    if (buffer.length === 0) {
        return;
    }
    const entries = buffer;
    buffer = [];
    post('/logs', { service, hostname: hostname(), entries });
}

function heartbeat(): void {
    post('/heartbeat', {
        service,
        hostname: hostname(),
        uptime_seconds: Math.round(process.uptime()),
        memory_mb: Math.round(process.memoryUsage().rss / 1024 / 1024),
    });
}

/**
 * Liga o push remoto. Sem OBSERVABILITY_URL/TOKEN configurados, não faz nada
 * (log local continua normal).
 */
export function initObservability(serviceName: string): void {
    baseUrl = (process.env['OBSERVABILITY_URL'] ?? '').replace(/\/+$/, '');
    token = process.env['OBSERVABILITY_TOKEN'] ?? '';
    service = process.env['SERVICE_NAME'] ?? serviceName;

    if (!baseUrl || !token) {
        logger.warn(
            '[Observability] OBSERVABILITY_URL/OBSERVABILITY_TOKEN não configurados — push remoto desativado.',
        );
        return;
    }

    // Decora o logger: console continua sendo a saída primária.
    const original = { ...logger };
    for (const level of ['debug', 'info', 'warn', 'error'] as const) {
        logger[level] = (message: string): void => {
            original[level](message);
            buffer.push({ level, message, context: null, logged_at: new Date().toISOString() });
            if (buffer.length >= BUFFER_HARD_CAP) {
                buffer = buffer.slice(-FLUSH_MAX_ENTRIES); // backpressure: descarta os antigos
            } else if (buffer.length >= FLUSH_MAX_ENTRIES) {
                flush();
            }
        };
    }

    setInterval(flush, FLUSH_INTERVAL_MS).unref();
    heartbeat();
    setInterval(heartbeat, HEARTBEAT_INTERVAL_MS).unref();

    original.info(`[Observability] Push remoto ligado (${service} → ${baseUrl}).`);
}
