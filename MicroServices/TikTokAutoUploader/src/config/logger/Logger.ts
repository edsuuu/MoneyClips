import { LogLevel } from '@/types/LogLevelType';

const COLORS: Record<LogLevel, string> = {
    [LogLevel.DEBUG]: '\x1b[90m',
    [LogLevel.INFO]: '\x1b[36m',
    [LogLevel.WARN]: '\x1b[33m',
    [LogLevel.ERROR]: '\x1b[31m',
};

const RESET = '\x1b[0m';

function write(level: LogLevel, message: string): void {
    const now = new Date();
    const ts = now.toTimeString().slice(0, 8);
    const color = COLORS[level];
    const stream = level === LogLevel.ERROR ? console.error : console.log;
    stream(`${color}[${ts}] ${level.padEnd(5)}${RESET} ${message}`);
}

/** Singleton de log com cores no terminal. */
export const logger = {
    debug: (message: string): void => write(LogLevel.DEBUG, message),
    info: (message: string): void => write(LogLevel.INFO, message),
    warn: (message: string): void => write(LogLevel.WARN, message),
    error: (message: string): void => write(LogLevel.ERROR, message),
};
