export type LogLevel = 'debug' | 'info' | 'warn' | 'error';

export type LogSink = (level: LogLevel, message: string) => void;

const COLORS: Record<LogLevel, string> = {
    debug: '\x1b[90m',
    info: '\x1b[36m',
    warn: '\x1b[33m',
    error: '\x1b[31m',
};

const RESET = '\x1b[0m';

export class Logger {
    private readonly sinks: LogSink[] = [];

    /**
     * Registra um destino extra (a observabilidade remota usa isto). O console
     * segue como saída primária — um sink NUNCA substitui a escrita local, e por
     * isso não existe monkey-patch dos métodos aqui.
     */
    public addSink(sink: LogSink): void {
        this.sinks.push(sink);
    }

    public debug(message: string): void {
        this.write('debug', message);
    }

    public info(message: string): void {
        this.write('info', message);
    }

    public warn(message: string): void {
        this.write('warn', message);
    }

    public error(message: string): void {
        this.write('error', message);
    }

    private write(level: LogLevel, message: string): void {
        const ts = new Date().toTimeString().slice(0, 8);
        const stream = level === 'error' ? console.error : console.log;
        stream(`${COLORS[level]}[${ts}] ${level.toUpperCase().padEnd(5)}${RESET} ${message}`);

        for (const sink of this.sinks) {
            sink(level, message);
        }
    }
}

export const logger = new Logger();
