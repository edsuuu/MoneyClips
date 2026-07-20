export type LogLevel = 'debug' | 'info' | 'warn' | 'error';

export type LogSink = (level: LogLevel, message: string) => void;

const COLORS: Record<LogLevel, string> = {
    debug: '\x1b[90m',
    info: '\x1b[36m',
    warn: '\x1b[33m',
    error: '\x1b[31m',
};

const RESET = '\x1b[0m';

/**
 * Base de log: quem precisa logar estende e chama `this.info(...)`.
 *
 * Os sinks são ESTÁTICOS de propósito — a observabilidade remota registra um
 * só e ele tem que valer para todas as subclasses. Se fossem de instância,
 * cada objeto teria seu próprio destino e só o log de quem registrou subiria
 * pro Laravel.
 */
export abstract class Logger {
    private static readonly sinks: LogSink[] = [];

    public static addSink(sink: LogSink): void {
        Logger.sinks.push(sink);
    }

    private static write(level: LogLevel, message: string): void {
        const ts = new Date().toTimeString().slice(0, 8);
        const stream = level === 'error' ? console.error : console.log;
        stream(`${COLORS[level]}[${ts}] ${level.toUpperCase().padEnd(5)}${RESET} ${message}`);

        for (const sink of Logger.sinks) {
            sink(level, message);
        }
    }

    protected debug(message: string): void {
        Logger.write('debug', message);
    }

    protected info(message: string): void {
        Logger.write('info', message);
    }

    protected warn(message: string): void {
        Logger.write('warn', message);
    }

    protected error(message: string): void {
        Logger.write('error', message);
    }
}
